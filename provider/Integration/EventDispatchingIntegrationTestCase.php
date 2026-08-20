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
use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use PHPUnit\Framework\TestCase;

abstract class EventDispatchingIntegrationTestCase extends TestCase
{
    /**
     * @var list<array<string, mixed>>
     */
    public static array $handledLog = [];

    /**
     * @var list<array<string, mixed>>
     */
    public static array $handledLog1;

    /**
     * @var list<array<string, mixed>>
     */
    public static array $handledLog2;

    abstract protected function getStorage(): EventStorageInterface;
    abstract protected function getSnapshotStorage(): SnapshotStorageInterface;
    abstract protected function getSnapshotHistoryStorage(): SnapshotHistoryStorageInterface;

    protected function setUp(): void
    {
        parent::setUp();

        self::$handledLog = [];
    }

    public function test_eventDispatchingSubscribers_workCorrectly(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->register(new class() implements EventSubscriberInterface {
            public static function getSubscribedTo(): array
            {
                return [AnotherDummyEvent::class];
            }

            public function handle(DomainEventInterface $event): void
            {
                EventDispatchingIntegrationTestCase::$handledLog[] = $event->toArray();
            }
        });

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $aggregateId = EntityIdentifier::fromString('agg-1');

        for ($i = 1; $i <= 5; $i++) {
            $eventId = uniqid('event_', true);
            $delta = ($i % 2 === 0) ? 2 : 1;
            $event = new AnotherDummyEvent(
                $aggregateId,
                $eventId,
                $delta,
                new DateTimeImmutable(),
                $i
            );

            try {
                $eventStorage->storeEvents([$event]);
                $dispatcher->dispatchAll([$event]);
            } catch (ConcurrencyException $e) {
                $this->fail("ConcurrencyException unexpectedly thrown: " . $e->getMessage());
            }
        }

        $retrievedEvents = $eventStorage->retrieveEvents($aggregateId);
        $this->assertCount(5, $retrievedEvents);

        $this->assertCount(5, self::$handledLog, 'Subscriber should have handled 5 events');

        $deltas = array_map(static fn (array $item): mixed => $item['delta'] ?? null, self::$handledLog);
        $this->assertSame([1, 2, 1, 2, 1], $deltas, 'Expected alternating deltas 1,2,1,2,1');
    }

    /**
     * @throws ConcurrencyException
     */
    public function test_subscriberDoesNotReceiveNonSubscribedEvents(): void
    {
        self::$handledLog = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->register(new class() implements EventSubscriberInterface {
            public static function getSubscribedTo(): array
            {
                return [AnotherDummyEvent::class];
            }
            public function handle(DomainEventInterface $event): void
            {
                EventDispatchingIntegrationTestCase::$handledLog[] = $event->toArray();
            }
        });

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $aggregateId = EntityIdentifier::fromString('agg-2');
        $eventId = uniqid('event_', true);

        $event = new NonSubscribedDummyEvent(
            $aggregateId,
            $eventId,
            42,
            new DateTimeImmutable(),
            1
        );
        $eventStorage->storeEvents([$event]);
        $dispatcher->dispatchAll([$event]);

        $this->assertCount(0, self::$handledLog, "Subscriber should not handle non-subscribed events.");
    }

    /**
     * @throws ConcurrencyException
     */
    public function test_multipleSubscribersReceiveEvents(): void
    {
        self::$handledLog1 = [];
        self::$handledLog2 = [];

        $dispatcher = new EventDispatcher();

        $dispatcher->register(new class() implements EventSubscriberInterface {
            public static function getSubscribedTo(): array
            {
                return [AnotherDummyEvent::class];
            }
            public function handle(DomainEventInterface $event): void
            {
                EventDispatchingIntegrationTestCase::$handledLog1[] = $event->toArray();
            }
        });

        $dispatcher->register(new class() implements EventSubscriberInterface {
            public static function getSubscribedTo(): array
            {
                return [AnotherDummyEvent::class];
            }
            public function handle(DomainEventInterface $event): void
            {
                EventDispatchingIntegrationTestCase::$handledLog2[] = $event->toArray();
            }
        });

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $aggregateId = EntityIdentifier::fromString('agg-3');
        $eventId = uniqid('event_', true);
        $event = new AnotherDummyEvent(
            $aggregateId,
            $eventId,
            99,
            new DateTimeImmutable(),
            1
        );
        $eventStorage->storeEvents([$event]);
        $dispatcher->dispatchAll([$event]);

        $this->assertCount(1, self::$handledLog1, "First subscriber should handle 1 event.");
        $this->assertCount(1, self::$handledLog2, "Second subscriber should handle 1 event.");
    }

    /**
     * @throws ConcurrencyException
     */
    public function test_duplicateSubscriberRegistration_doesNotCallTwice(): void
    {
        self::$handledLog = [];
        $dispatcher = new EventDispatcher();
        $subscriber = new class() implements EventSubscriberInterface {
            public static function getSubscribedTo(): array
            {
                return [AnotherDummyEvent::class];
            }
            public function handle(DomainEventInterface $event): void
            {
                EventDispatchingIntegrationTestCase::$handledLog[] = $event->toArray();
            }
        };

        $dispatcher->register($subscriber);
        $dispatcher->register($subscriber);

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $aggregateId = EntityIdentifier::fromString('agg-4');
        $eventId = uniqid('event_', true);
        $event = new AnotherDummyEvent(
            $aggregateId,
            $eventId,
            50,
            new DateTimeImmutable(),
            1
        );
        $eventStorage->storeEvents([$event]);
        $dispatcher->dispatchAll([$event]);

        $this->assertCount(1, self::$handledLog, "Duplicate subscriber registration should not result in duplicate calls.");
    }

    /**
     * @throws ConcurrencyException
     */
    public function test_eventDispatchOrder(): void
    {
        self::$handledLog = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->register(new class() implements EventSubscriberInterface {
            public static function getSubscribedTo(): array
            {
                return [AnotherDummyEvent::class];
            }
            public function handle(DomainEventInterface $event): void
            {
                EventDispatchingIntegrationTestCase::$handledLog[] = $event->toArray();
            }
        });

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $aggregateId = EntityIdentifier::fromString('agg-5');

        $eventIds = [];
        for ($i = 1; $i <= 3; $i++) {
            $eventId = uniqid('event_', true);
            $eventIds[] = $eventId;
            $event = new AnotherDummyEvent(
                $aggregateId,
                $eventId,
                $i,
                new DateTimeImmutable(),
                $i
            );
            $eventStorage->storeEvents([$event]);
            $dispatcher->dispatchAll([$event]);
        }

        $loggedIds = array_map(static fn (array $item): mixed => $item['eventId'], self::$handledLog);
        $this->assertSame($eventIds, $loggedIds, "Dispatched event order should match the append order.");
    }
}

// dummy class
final class AnotherDummyEvent extends SourceEvent
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

final class NonSubscribedDummyEvent extends SourceEvent
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
