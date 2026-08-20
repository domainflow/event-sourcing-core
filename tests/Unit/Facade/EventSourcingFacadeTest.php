<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Facade;

use DateMalformedStringException;
use DateTimeImmutable;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Concurrency\ConcurrencyCheckingStorage;
use DomainFlow\EventSourcing\Concurrency\ConcurrencyCheckStrategyInterface;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventMetadata;
use DomainFlow\EventSourcing\Event\EventStream;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Facade\EventSourcingFacade;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventDispatcherInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotableAggregateInterface;
use DomainFlow\EventSourcing\Interface\SnapshotFactoryInterface;
use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use DomainFlow\EventSourcing\Repository\AggregateRepository;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionException;

#[UsesClass(OccurredOn::class)]
#[CoversClass(EntityIdentifier::class)]
#[CoversClass(EventId::class)]
#[CoversClass(EventVersion::class)]
#[CoversClass(EventSourcingFacade::class)]
#[CoversClass(AggregateRepository::class)]
#[CoversClass(ConcurrencyCheckingStorage::class)]
#[CoversClass(AggregateRoot::class)]
#[CoversClass(EventStream::class)]
#[CoversClass(EventEntry::class)]
#[UsesClass(EventMetadata::class)]
#[UsesTrait(HasEventMetadata::class)]
final class EventSourcingFacadeTest extends TestCase
{
    private EventStorageInterface&Stub $eventStorage;
    private SnapshotStorageInterface&Stub $snapshotStorage;
    private SnapshotFactoryInterface&Stub $snapshotFactory;
    private SnapshotHistoryStorageInterface&Stub $snapshotHistory;
    private EventDispatcherInterface&Stub $dispatcher;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->eventStorage = $this->createStub(EventStorageInterface::class);
        $this->snapshotStorage = $this->createStub(SnapshotStorageInterface::class);
        $this->snapshotFactory = $this->createStub(SnapshotFactoryInterface::class);
        $this->snapshotHistory = $this->createStub(SnapshotHistoryStorageInterface::class);
        $this->dispatcher = $this->createStub(EventDispatcherInterface::class);
    }

    /**
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_load_delegates_to_repository(): void
    {
        $facade = new EventSourcingFacade($this->eventStorage);
        $id = EntityIdentifier::fromString('abc');

        $this->eventStorage
            ->method('retrieveEvents')
            ->willReturn([]);

        $result = $facade->load(DummyAggregate::class, $id);

        $this->assertInstanceOf(DummyAggregate::class, $result);
    }

    /**
     * @throws Exception
     */
    public function test_persist_saves_and_dispatches_events(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $facade = new EventSourcingFacade($this->eventStorage, dispatcher: $dispatcher);

        $aggregate = new DummyAggregate();
        $event = new DummyEvent();
        $aggregate->applyTestEvent($event);

        $dispatcher
            ->expects($this->once())
            ->method('dispatchAll')
            ->with([$event]);

        $facade->persist($aggregate);
    }

    /**
     * @throws Exception
     */
    public function test_apply_calls_load_and_persist(): void
    {
        $id = EntityIdentifier::fromString('abc');
        $aggregate = new DummyAggregate();
        $aggregate->applyTestEvent(new DummyEvent());

        $eventStorage = $this->createMock(EventStorageInterface::class);
        $eventStorage->method('retrieveEvents')->willReturn($aggregate->getUncommittedEvents());
        $eventStorage->expects($this->once())->method('storeEvents');

        $facade = new EventSourcingFacade($eventStorage);
        $facade->apply(DummyAggregate::class, $id, function ($agg) {
            $this->assertInstanceOf(DummyAggregate::class, $agg);
            $agg->applyTestEvent(new DummyEvent());
        });

    }

    public function test_delete_delegates_to_repository(): void
    {
        $facade = new EventSourcingFacade($this->eventStorage);
        $facade->delete(EntityIdentifier::fromString('some-id'));
        $this->addToAssertionCount(1);
    }

    public function test_createAndPersistSnapshot_returns_null_if_not_snapshotable(): void
    {
        $facade = new EventSourcingFacade($this->eventStorage);
        $nonSnapshotable = new DummyAggregate();
        $this->assertNull($facade->createAndPersistSnapshot($nonSnapshotable));
    }

    /**
     * @throws Exception
     */
    public function test_createAndPersistSnapshot_persists_snapshot(): void
    {
        $snapshot = $this->createStub(SnapshotInterface::class);
        $aggregate = new DummySnapshotableAggregate();

        $snapshotFactory = $this->createMock(SnapshotFactoryInterface::class);
        $snapshotFactory->expects($this->once())
            ->method('createFromStorage')
            ->willReturn($snapshot);

        $snapshotStorage = $this->createMock(SnapshotStorageInterface::class);
        $snapshotStorage->expects($this->once())
            ->method('storeSnapshot')
            ->with($snapshot);

        $facade = new EventSourcingFacade(
            eventStorage: $this->eventStorage,
            snapshotStorage: $snapshotStorage,
            snapshotFactory: $snapshotFactory,
            snapshotHistory: $this->snapshotHistory
        );

        $aggregate->applyTestEvent(new DummyEvent());

        $result = $facade->createAndPersistSnapshot($aggregate);
        $this->assertSame($snapshot, $result);
    }

    /**
     * @throws Exception
     */
    public function test_replay_calls_event_storage(): void
    {
        $id = EntityIdentifier::fromString('xyz');
        $event = new DummyEvent();

        $eventStorage = $this->createMock(EventStorageInterface::class);
        $eventStorage
            ->expects($this->once())
            ->method('retrieveEvents')
            ->with($id)
            ->willReturn([$event]);

        $facade = new EventSourcingFacade($eventStorage);
        $result = $facade->replay($id);

        $this->assertSame([$event], $result);
    }

    /**
     * @throws Exception
     */
    public function test_enableConcurrencyCheck_wraps_storage(): void
    {
        $strategy = $this->createStub(ConcurrencyCheckStrategyInterface::class);
        $facade = new EventSourcingFacade($this->eventStorage);
        $facade->enableConcurrencyCheck($strategy);

        $this->addToAssertionCount(1);
    }
}

// dummy classes
class DummyAggregate extends AggregateRoot
{
    /** @var DomainEventInterface[] */
    private array $events = [];

    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function applyTestEvent(
        DomainEventInterface $event
    ): void {
        $this->events[] = $event;
    }

    public function getUncommittedEvents(): array
    {
        return $this->events;
    }
}

final class DummySnapshotableAggregate extends DummyAggregate implements SnapshotableAggregateInterface
{
    public function shouldTakeSnapshot(): bool
    {
        return true;
    }

    public function getSnapshotClass(): string
    {
        return DummySnapshot::class;

    }

    public function getSnapshotState(): array
    {
        return [
            'id' => 'dummy-id',
            'version' => 1,
        ];
    }

    public function getSnapshotVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString('facade-dummy-snapshotable');
    }

    public function applySnapshot(
        SnapshotInterface $snapshot
    ): void {
    }
}

final class DummyEvent implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public function __construct()
    {
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return new class() implements EntityIdentifierInterface {
            public function __toString(): string
            {
                return 'dummy-id';
            }
            public function equals(
                EntityIdentifierInterface $other
            ): bool {
                return true;
            }

            public static function fromString(
                string $value
            ): EntityIdentifierInterface {
            }
        };
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return ['id' => 'dummy-id'];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class DummySnapshot implements SnapshotInterface
{
    public function __construct(
        private readonly string $id = 'dummy-id',
        private readonly int $version = 1,
        private readonly array $state = [],
    ) {
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString($this->id);
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt($this->version);
    }

    public function getState(): array
    {
        return $this->state;
    }

    public function getOccurredOn(): OccurredOn
    {
        return OccurredOn::now();
    }
}
