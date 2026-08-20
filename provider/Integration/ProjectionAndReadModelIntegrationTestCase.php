<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Integration;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Concurrency\ConcurrencyCheckingStorage;
use DomainFlow\EventSourcing\Concurrency\MaxVersionStrategy;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventDispatcher;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Exception\ConcurrencyException;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\ProjectorInterface;
use PHPUnit\Framework\TestCase;

abstract class ProjectionAndReadModelIntegrationTestCase extends TestCase
{
    abstract protected function getStorage(): EventStorageInterface;
    abstract protected function getCounterProjectionRepository(): CounterProjectionRepositoryInterface;
    abstract protected function setupCounterProjections(): void;
    abstract protected function getCounterFromProjection(string $aggregateId): ?int;
    abstract protected function getProjectionCounter(string $aggregateId): ?int;
    abstract protected function projectionRowExists(string $aggregateId): bool;

    public function setUp(): void
    {
        $this->setupCounterProjections();
    }

    /**
     * @throws ConcurrencyException
     */
    public function test_projectionReadModelForMultipleAggregates(): void
    {
        $repo = $this->getCounterProjectionRepository();
        $dispatcher = new EventDispatcher();
        $dispatcher->register(new CounterProjector($repo));

        $storage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $aggregate1 = EntityIdentifier::fromString('agg-1');
        for ($i = 1; $i <= 5; $i++) {
            $delta = ($i % 2 === 0) ? 2 : 1;
            $storage->storeEvents([new AndAnotherDummyEvent($aggregate1, uniqid('event_', true), $delta, new DateTimeImmutable(), $i)]);
            $dispatcher->dispatchAll([new AndAnotherDummyEvent($aggregate1, uniqid('event_', true), $delta, new DateTimeImmutable(), $i)]);
        }

        $aggregate2 = EntityIdentifier::fromString('agg-2');
        for ($i = 1; $i <= 3; $i++) {
            $storage->storeEvents([new AndAnotherDummyEvent($aggregate2, uniqid('event_', true), 3, new DateTimeImmutable(), $i)]);
            $dispatcher->dispatchAll([new AndAnotherDummyEvent($aggregate2, uniqid('event_', true), 3, new DateTimeImmutable(), $i)]);
        }

        $this->assertSame(7, $repo->getCounter((string) $aggregate1));
        $this->assertSame(9, $repo->getCounter((string) $aggregate2));
    }
    /**
     * @throws ConcurrencyException
     */
    public function test_projectionResetAndRebuild(): void
    {
        $dispatcher = new EventDispatcher();
        $counterProjector = new CounterProjector($this->getCounterProjectionRepository());
        $dispatcher->register($counterProjector);

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $aggregate = EntityIdentifier::fromString('agg-3');

        $events = [];
        for ($i = 1; $i <= 4; $i++) {
            $eventId = uniqid('event_', true);
            $event = new AndAnotherDummyEvent($aggregate, $eventId, 2, new DateTimeImmutable(), $i);
            $events[] = $event;
            $eventStorage->storeEvents([$event]);
            $dispatcher->dispatchAll([$event]);
        }

        $this->assertNotNull(
            $this->getProjectionCounter((string) $aggregate),
            "Projection row should exist before reset."
        );
        $this->assertSame(8, $this->getProjectionCounter((string) $aggregate));

        $counterProjector->reset();
        $this->assertFalse(
            $this->projectionRowExists((string) $aggregate),
            "Projection row should not exist after reset."
        );

        $counterProjector->replay(...$events);
        $this->assertNotNull(
            $this->getProjectionCounter((string) $aggregate),
            "Projection row should exist after replay."
        );
        $this->assertSame(
            8,
            $this->getProjectionCounter((string) $aggregate),
            "Projection counter should equal total delta from replayed events."
        );
    }

    public function test_projectionRemainsEmptyWhenNoEvents(): void
    {
        $repo = $this->getCounterProjectionRepository();
        $projector = new CounterProjector($repo);
        $projector->reset();
        $this->assertCount(0, $repo->all());
    }
}

// dummy classes
final class AndAnotherDummyEvent extends SourceEvent
{
    private int $delta;

    public function __construct(
        EntityIdentifierInterface $aggregateId,
        string $eventId,
        int $delta,
        ?DateTimeImmutable $occurredOn = null,
        int $version = 1
    ) {
        parent::__construct($aggregateId, EntityIdentifier::fromString($eventId), $occurredOn, EventVersion::fromInt($version));
        $this->delta = $delta;
    }

    public function getDelta(): int
    {
        return $this->delta;
    }

    public function toArray(): array
    {
        $base = parent::toArray();
        $base['delta'] = $this->delta;

        return $base;
    }
}

final class CounterProjector implements ProjectorInterface
{
    public function __construct(
        private readonly CounterProjectionRepositoryInterface $repository
    ) {
    }

    public static function getSubscribedTo(): array
    {
        return [AndAnotherDummyEvent::class];
    }

    public function handle(
        DomainEventInterface $event
    ): void {
        if (!$this->supports($event::class)) {
            return;
        }

        /** @var AndAnotherDummyEvent $event */
        $id = (string) $event->getAggregateId();
        $delta = $event->getDelta();
        $current = $this->repository->getCounter($id) ?? 0;

        $this->repository->saveCounter($id, $current + $delta);
    }

    public function reset(): void
    {
        $this->repository->reset();
    }

    public function replay(
        DomainEventInterface ...$events
    ): void {
        foreach ($events as $event) {
            if ($this->supports($event::class)) {
                $this->handle($event);
            }
        }
    }

    public function supports(
        string $eventClass
    ): bool {
        return in_array($eventClass, self::getSubscribedTo(), true);
    }

    public function getName(): string
    {
        return 'CounterProjector';
    }
}

interface CounterProjectionRepositoryInterface
{
    public function getCounter(string $aggregateId): ?int;
    public function saveCounter(string $aggregateId, int $counter): void;
    public function reset(): void;
    /**
     * @return array<string, mixed>[]
     */
    public function all(): array;
}
