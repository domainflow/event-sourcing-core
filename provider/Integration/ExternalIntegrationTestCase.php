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
use DomainFlow\EventSourcing\Interface\EventSubscriberInterface;
use PHPUnit\Framework\TestCase;

abstract class ExternalIntegrationTestCase extends TestCase
{
    abstract protected function getStorage(): EventStorageInterface;

    /**
     * @throws ConcurrencyException
     */
    public function test_externalIntegrationForwardsEvents(): void
    {
        $fakeBus = new FakeExternalMessageBus();

        $externalSubscriber = new ExternalIntegrationSubscriber($fakeBus);

        $dispatcher = new EventDispatcher();
        $dispatcher->register($externalSubscriber);

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $aggregateId = EntityIdentifier::fromString('agg-ext-1');

        for ($i = 1; $i <= 3; $i++) {
            $eventId = uniqid('event_', true);

            $delta = 10;
            $event = new ExternalDummyEvent($aggregateId, $eventId, $delta, new DateTimeImmutable(), $i);
            $eventStorage->storeEvents([$event]);
            $dispatcher->dispatchAll([$event]);
        }

        $this->assertCount(3, $fakeBus->getSentEvents(), "Fake bus should have sent 3 events.");

        foreach ($fakeBus->getSentEvents() as $sent) {
            $this->assertArrayHasKey('eventId', $sent);
            $this->assertArrayHasKey('delta', $sent);
            $this->assertSame(10, $sent['delta']);
        }
    }

    /**
     * @throws ConcurrencyException
     */
    public function test_externalIntegrationIgnoresNonSubscribedEvents(): void
    {
        $fakeBus = new FakeExternalMessageBus();
        $externalSubscriber = new ExternalIntegrationSubscriber($fakeBus);

        $dispatcher = new EventDispatcher();
        $dispatcher->register($externalSubscriber);

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $aggregateId = EntityIdentifier::fromString('agg-ext-2');

        $nonSubscribedEvent = new NonSubscribedEvent(
            $aggregateId,
            uniqid('event_', true),
            999,
            new DateTimeImmutable(),
            1
        );
        $eventStorage->storeEvents([$nonSubscribedEvent]);
        $dispatcher->dispatchAll([$nonSubscribedEvent]);

        $this->assertCount(0, $fakeBus->getSentEvents(), "Fake bus should not forward non-subscribed events.");
    }

    /**
     * @throws ConcurrencyException
     */
    public function test_externalIntegrationEventOrder(): void
    {
        $fakeBus = new FakeExternalMessageBus();
        $externalSubscriber = new ExternalIntegrationSubscriber($fakeBus);

        $dispatcher = new EventDispatcher();
        $dispatcher->register($externalSubscriber);

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $aggregateId = EntityIdentifier::fromString('agg-ext-3');

        $eventIds = [];
        for ($i = 1; $i <= 4; $i++) {
            $eventId = uniqid('event_', true);
            $eventIds[] = $eventId;
            $event = new ExternalDummyEvent($aggregateId, $eventId, 5, new DateTimeImmutable(), $i);
            $eventStorage->storeEvents([$event]);
            $dispatcher->dispatchAll([$event]);
        }

        $sentEvents = $fakeBus->getSentEvents();
        $this->assertCount(4, $sentEvents, "Fake bus should have forwarded 4 events.");
        $loggedIds = array_map(fn (array $item) => $item['eventId'] ?? null, $sentEvents);
        $this->assertSame($eventIds, $loggedIds, "The order of forwarded events should match the order of append.");
    }

    /**
     * @throws ConcurrencyException
     */
    public function test_externalIntegrationDispatchSameEventTwice(): void
    {
        $fakeBus = new FakeExternalMessageBus();
        $externalSubscriber = new ExternalIntegrationSubscriber($fakeBus);

        $dispatcher = new EventDispatcher();
        $dispatcher->register($externalSubscriber);

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $aggregateId = EntityIdentifier::fromString('agg-ext-4');
        $eventId = uniqid('event_', true);
        $event = new ExternalDummyEvent($aggregateId, $eventId, 10, new DateTimeImmutable(), 1);

        $eventStorage->storeEvents([$event]);
        $dispatcher->dispatchAll([$event]);

        $dispatcher->dispatch($event);

        $this->assertCount(2, $fakeBus->getSentEvents(), "Fake bus should have forwarded the same event twice.");
    }
}

// dummy classes
final class FakeExternalMessageBus
{
    /** @var array<int, array<string, mixed>> */
    private array $sentEvents = [];

    public function send(
        DomainEventInterface $event
    ): void {
        $this->sentEvents[] = $event->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSentEvents(): array
    {
        return $this->sentEvents;
    }
}

final class ExternalIntegrationSubscriber implements EventSubscriberInterface
{
    private FakeExternalMessageBus $bus;

    public function __construct(
        FakeExternalMessageBus $bus
    ) {
        $this->bus = $bus;
    }

    public static function getSubscribedTo(): array
    {
        return [ExternalDummyEvent::class];
    }

    public function handle(
        DomainEventInterface $event
    ): void {
        $this->bus->send($event);
    }
}

final class ExternalDummyEvent extends SourceEvent
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

final class NonSubscribedEvent extends SourceEvent
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
