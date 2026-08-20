<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Acceptance;

use DateMalformedStringException;
use DateTimeImmutable;
use DomainFlow\EventSourcing\Aggregate\AggregateId;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Facade\EventSourcingFacade;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventDispatcherInterface;
use DomainFlow\EventSourcing\Interface\EventSubscriberInterface;
use DomainFlow\EventSourcing\Interface\SnapshotableAggregateInterface;
use DomainFlow\EventSourcing\Interface\SnapshotFactoryInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshot;
use DomainFlow\EventSourcing\Storage\InMemoryEventStorage;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use DomainFlow\EventSourcing\Trait\SnapshotableAggregateTrait;
use DomainFlow\Uuid\UuidV6;
use Exception;
use JsonSerializable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use ReflectionException;
use RuntimeException;

#[CoversNothing]
final class EventSourcingFacadeAcceptanceTest extends TestCase
{
    /**
     * @throws DateMalformedStringException|ReflectionException|Exception
     */
    public function test_can_apply_mutation_and_replay_events(): void
    {
        $storage = new InMemoryEventStorage();
        $dispatcher = new class() implements EventDispatcherInterface {
            /** @var array<DomainEventInterface> */
            public array $dispatched = [];
            public function register(
                EventSubscriberInterface $subscriber
            ): void {
            }
            public function dispatch(
                DomainEventInterface $event
            ): void {
                $this->dispatched[] = $event;
            }
            public function dispatchAll(
                array $events
            ): void {
                foreach ($events as $event) {
                    $this->dispatch($event);
                }
            }
        };

        $eventStore = new EventSourcingFacade(
            eventStorage: $storage,
            snapshotStorage: null,
            snapshotFactory: null,
            snapshotHistory: null,
            dispatcher: $dispatcher
        );

        $id = DummyId::new();
        $aggregateClass = DummyAggregate::class;

        $eventStore->apply($aggregateClass, $id, function (DummyAggregate $agg) use ($id) {
            $agg->recordSomethingWithId($id, 'hello');
        });

        $loaded = $eventStore->load($aggregateClass, $id);

        $this->assertInstanceOf(DummyAggregate::class, $loaded);
        $this->assertSame(['hello'], $loaded->getMessages());

        $events = $eventStore->replay($id);

        $this->assertCount(1, $events);
        $this->assertInstanceOf(DummyEvent::class, $events[0]);
        $this->assertSame('hello', $events[0]->message);

        $this->assertCount(1, $dispatcher->dispatched);
        $this->assertEquals($events[0], $dispatcher->dispatched[0]);
    }

    /**
     * @throws RandomException
     */
    public function test_can_persist_directly(): void
    {
        $storage = new InMemoryEventStorage();
        $dispatcher = new class() implements EventDispatcherInterface {
            /** @var array<DomainEventInterface> */
            public array $dispatched = [];
            public function register(
                EventSubscriberInterface $subscriber
            ): void {
            }
            public function dispatch(
                DomainEventInterface $event
            ): void {
                $this->dispatched[] = $event;
            }
            public function dispatchAll(
                array $events
            ): void {
                foreach ($events as $event) {
                    $this->dispatch($event);
                }
            }
        };

        $facade = new EventSourcingFacade($storage, dispatcher: $dispatcher);

        $id = DummyId::new();
        $agg = new DummyAggregate();
        $agg->recordSomethingWithId($id, 'persisted');

        $facade->persist($agg);

        $events = $facade->replay($id);

        $this->assertCount(1, $events);
        $this->assertSame('persisted', $events[0]->message);
        $this->assertCount(1, $dispatcher->dispatched);
    }

    /**
     * @throws RandomException
     */
    public function test_create_and_persist_snapshot(): void
    {
        $storage = new InMemoryEventStorage();
        $facade = new EventSourcingFacade(
            eventStorage: $storage,
            snapshotStorage: new class() implements SnapshotStorageInterface {
                public ?SnapshotInterface $snapshot = null;
                public function storeSnapshot(
                    SnapshotInterface $snapshot
                ): void {
                    $this->snapshot = $snapshot;
                }
                public function retrieveSnapshot(
                    EntityIdentifierInterface $aggregateId
                ): ?SnapshotInterface {
                    return $this->snapshot;
                }
                public function deleteSnapshot(
                    EntityIdentifierInterface $aggregateId
                ): void {
                    $this->snapshot = null;
                }
            },
            snapshotFactory: new class() implements SnapshotFactoryInterface {
                public function createFromStorage(
                    string $snapshotClass,
                    EntityIdentifierInterface $aggregateId,
                    EventVersion $version,
                    array $state
                ): SnapshotInterface {
                    return new GenericSnapshot($aggregateId, $version, $state, new OccurredOn());
                }
            }
        );

        $id = DummyId::new();
        $agg = new SnapshotableDummyAggregate();
        $agg->recordSomethingWithId($id, 'snap');

        $snapshot = $facade->createAndPersistSnapshot($agg);

        $this->assertNotNull($snapshot);
        $this->assertArrayHasKey('id', $snapshot->getState());
        $this->assertNotEmpty($snapshot->getAggregateId());
        $this->assertTrue($id->equals($snapshot->getAggregateId()));
    }

    /**
     * @throws RandomException
     */
    public function test_delete_removes_events(): void
    {
        $storage = new InMemoryEventStorage();
        $facade = new EventSourcingFacade($storage);

        $id = DummyId::new();
        $agg = new DummyAggregate();
        $agg->recordSomethingWithId($id, 'bye');

        $facade->persist($agg);

        $this->assertCount(1, $facade->replay($id));

        $facade->delete($id);

        $this->assertCount(0, $facade->replay($id));
    }

    /**
     * @throws RandomException
     */
    public function test_replay_returns_event_history(): void
    {
        $storage = new InMemoryEventStorage();
        $facade = new EventSourcingFacade($storage);

        $id = DummyId::new();
        $agg = new DummyAggregate();
        $agg->recordSomethingWithId($id, 'one');
        $agg->recordSomething('two');

        $facade->persist($agg);

        $events = $facade->replay($id);
        $this->assertCount(2, $events);
        $this->assertSame('one', $events[0]->message);
        $this->assertSame('two', $events[1]->message);
    }

}

// dummy classes
class DummyAggregate extends AggregateRoot
{
    protected EntityIdentifierInterface $id;
    /** @var array<string> */
    private array $messages = [];

    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    /**
     * @throws RandomException
     */
    public function recordSomething(
        string $message
    ): void {
        $this->applyEvent(
            new DummyEvent(
                $this->id ?? DummyId::new(),
                $message,
                EventVersion::unassigned()
            )
        );
    }

    protected function applyDummyEvent(
        DummyEvent $event
    ): void {
        $this->id = $event->getAggregateId();
        $this->messages[] = $event->message;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function recordSomethingWithId(
        DummyId $id,
        string $message
    ): void {
        $this->id = $id;
        $this->applyEvent(new DummyEvent($id, $message, EventVersion::unassigned()));
    }
}

final class DummyEvent implements DomainEventInterface
{
    use HasEventMetadata;

    private DummyId $id;

    public function __construct(
        DummyId|string $id,
        public string $message,
        protected EventVersion $version
    ) {
        $this->id = DummyId::fromString((string) $id);
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->id;
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'message' => $this->message,
            'version' => $this->version,
        ];
    }

    public static function fromArray(
        array $data
    ): self {
        return new self(
            DummyId::fromString((string) $data['id']),
            $data['message'],
            EventVersion::fromInt((int) $data['version'] ?? 1),
        );

    }

    public static function getFactory(): callable
    {
        return [self::class, 'fromArray'];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final readonly class DummyId implements EntityIdentifierInterface, JsonSerializable
{
    private function __construct(
        private EntityIdentifierInterface $value
    ) {
    }

    public static function fromString(
        string $value
    ): self {
        return new self(AggregateId::fromString($value));
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }

    public function equals(EntityIdentifierInterface $other): bool
    {
        return (string) $this === (string) $other;
    }

    /**
     * @throws RandomException
     */
    public static function new(): self
    {
        return new self(
            AggregateId::fromString((string) UuidV6::generate())
        );
    }

    public function jsonSerialize(): string
    {
        return (string) $this->value;
    }
}

final class SnapshotableDummyAggregate extends DummyAggregate implements SnapshotableAggregateInterface
{
    use SnapshotableAggregateTrait;

    public function shouldTakeSnapshot(): bool
    {
        return true;
    }

    public function getSnapshotState(): array
    {
        if (!isset($this->id)) {
            throw new RuntimeException('Snapshotable aggregate is missing ID');
        }

        return [
            'id' => (string) $this->id,
            'messages' => $this->getMessages(),
            'version' => $this->getSnapshotVersion(),
        ];
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->id;
    }

    public function applySnapshot(
        SnapshotInterface $snapshot
    ): void {
        $state = $snapshot->getState();

        if (isset($state['id']) && is_string($state['id'])) {
            $this->id = DummyId::fromString($state['id']);
        }
    }
}
