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
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\ProjectorInterface;
use PHPUnit\Framework\TestCase;

abstract class ProjectionReplayIntegrationTestCase extends TestCase
{
    abstract protected function getStorage(): EventStorageInterface;
    abstract protected function getCounterProjectionRepository(): ProjectorInterface;
    abstract protected function setupCounterProjections(): void;
    abstract protected function getProjectionCounter(string $aggregateId): ?int;
    abstract protected function projectionRowExists(string $aggregateId): bool;

    /** @return array<string, mixed> */
    abstract protected function getAllProjectionRows(): array;

    protected function setUp(): void
    {
        $this->setupCounterProjections();
    }

    /**
     * @throws ConcurrencyException
     */
    public function test_projectionReplayUpdatesCounterCorrectly(): void
    {
        $dispatcher = new EventDispatcher();

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $aggregateId = EntityIdentifier::fromString('agg-1');
        $totalDelta = 0;

        for ($i = 1; $i <= 5; $i++) {
            $eventId = uniqid('event_', true);
            $delta = ($i % 2 === 0) ? 2 : 1;
            $totalDelta += $delta;

            $event = new ProjectorDummyEvent(
                $aggregateId,
                $eventId,
                $delta,
                new DateTimeImmutable(),
                $i
            );
            $eventStorage->storeEvents([$event]);
            $dispatcher->dispatchAll([$event]);
        }

        $storedEvents = $eventStorage->retrieveEvents($aggregateId);
        $this->assertCount(5, $storedEvents);

        $projector = $this->getCounterProjectionRepository();
        $projector->reset();

        $projector->replay(...$storedEvents);

        $counter = $this->getProjectionCounter((string) $aggregateId);
        $this->assertNotNull($counter, "Projection row should exist for aggregate.");
        $this->assertSame($totalDelta, $counter, "Projection counter should equal total delta from events.");
    }

    /**
     * @throws ConcurrencyException
     */
    public function test_resetClearsProjection(): void
    {
        $dispatcher = new EventDispatcher();

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $aggregateId = EntityIdentifier::fromString('agg-2');

        $event1 = new ProjectorDummyEvent($aggregateId, uniqid('event_', true), 3, new DateTimeImmutable(), 1);
        $event2 = new ProjectorDummyEvent($aggregateId, uniqid('event_', true), 4, new DateTimeImmutable(), 2);

        $eventStorage->storeEvents([$event1]);
        $dispatcher->dispatchAll([$event1]);
        $eventStorage->storeEvents([$event2]);
        $dispatcher->dispatchAll([$event2]);

        $storedEvents = $eventStorage->retrieveEvents($aggregateId);
        $projector = $this->getCounterProjectionRepository();

        $projector->reset();
        $projector->replay(...$storedEvents);

        $row = $this->projectionRowExists((string) $aggregateId);
        $this->assertTrue($row, "Projection row should exist for aggregate agg-2");

        $projector->reset();

        $row = $this->projectionRowExists((string) $aggregateId);
        $this->assertFalse($row, "After reset, projection row should not exist for aggregate agg-2");
    }

    public function test_replayEmptyEventSetDoesNothing(): void
    {
        $projector = $this->getCounterProjectionRepository();
        $projector->reset();
        $projector->replay();

        $rows = $this->getAllProjectionRows();
        $this->assertCount(0, $rows, "Replaying an empty event set should not create projection rows.");
    }

    /**
     * @throws ConcurrencyException
     */
    public function test_projectionIgnoresNonSupportedEvents(): void
    {
        $dispatcher = new EventDispatcher();
        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());
        $aggregateId = EntityIdentifier::fromString('agg-3');

        $event = new NonSupportedEvent($aggregateId, uniqid('event_', true), 10, new DateTimeImmutable(), 1);
        $eventStorage->storeEvents([$event]);
        $dispatcher->dispatchAll([$event]);

        $storedEvents = $eventStorage->retrieveEvents($aggregateId);
        $projector = $this->getCounterProjectionRepository();

        $projector->reset();
        $projector->replay(...$storedEvents);

        $this->assertFalse(
            $this->projectionRowExists((string) $aggregateId),
            "Projection row should not exist because event type is not supported."
        );
    }

    /**
     * @throws ConcurrencyException
     */
    public function test_multipleReplaysAccumulateCounter(): void
    {
        $dispatcher = new EventDispatcher();
        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());
        $aggregateId = EntityIdentifier::fromString('agg-4');

        $event1 = new ProjectorDummyEvent($aggregateId, uniqid('event_', true), 2, new DateTimeImmutable(), 1);
        $event2 = new ProjectorDummyEvent($aggregateId, uniqid('event_', true), 3, new DateTimeImmutable(), 2);

        $eventStorage->storeEvents([$event1]);
        $dispatcher->dispatchAll([$event1]);
        $eventStorage->storeEvents([$event2]);
        $dispatcher->dispatchAll([$event2]);

        $storedEvents = $eventStorage->retrieveEvents($aggregateId);
        $projector = $this->getCounterProjectionRepository();

        $projector->reset();
        $projector->replay(...$storedEvents);

        $this->assertTrue(
            $this->projectionRowExists((string) $aggregateId),
            "Projection row should exist after first replay."
        );
        $this->assertSame(5, $this->getProjectionCounter((string) $aggregateId));

        $event3 = new ProjectorDummyEvent($aggregateId, uniqid('event_', true), 4, new DateTimeImmutable(), 3);
        $eventStorage->storeEvents([$event3]);
        $dispatcher->dispatchAll([$event3]);

        $storedEvents = $eventStorage->retrieveEvents($aggregateId);
        $projector->reset();
        $projector->replay(...$storedEvents);

        $this->assertTrue(
            $this->projectionRowExists((string) $aggregateId),
            "Projection row should exist after second replay."
        );
        $this->assertSame(9, $this->getProjectionCounter((string) $aggregateId));
    }

}

// dummy classes
final class ProjectorDummyEvent extends SourceEvent
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

final class NonSupportedEvent extends SourceEvent
{
    private int $value;

    public function __construct(
        EntityIdentifierInterface $aggregateId,
        string $eventId,
        int $value,
        ?DateTimeImmutable $occurredOn = null,
        int $version = 1
    ) {
        parent::__construct($aggregateId, EntityIdentifier::fromString($eventId), $occurredOn, EventVersion::fromInt($version));
        $this->value = $value;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function toArray(): array
    {
        $base = parent::toArray();
        $base['value'] = $this->value;

        return $base;
    }
}
