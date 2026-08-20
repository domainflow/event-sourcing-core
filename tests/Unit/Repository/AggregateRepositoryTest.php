<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Repository;

use DateMalformedStringException;
use DateTimeImmutable;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventMetadata;
use DomainFlow\EventSourcing\Event\EventStream;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotableAggregateInterface;
use DomainFlow\EventSourcing\Interface\SnapshotFactoryInterface;
use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use DomainFlow\EventSourcing\Repository\AggregateRepository;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshot;
use DomainFlow\EventSourcing\Snapshot\InMemorySnapshotStorage;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;
use stdClass;

#[CoversClass(EventId::class)]
#[CoversClass(EventVersion::class)]
#[CoversClass(OccurredOn::class)]
#[CoversClass(AggregateRepository::class)]
#[CoversClass(EventStream::class)]
#[CoversClass(AggregateRoot::class)]
#[CoversClass(EntityIdentifier::class)]
#[CoversClass(EventEntry::class)]
#[UsesClass(GenericSnapshot::class)]
#[UsesClass(InMemorySnapshotStorage::class)]
#[UsesClass(EventMetadata::class)]
#[UsesTrait(HasEventMetadata::class)]
final class AggregateRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        DummyAggregate::reset();
        DummySnapshotableAggregate::reset();
        TestSnapshotableAggregate::reset();
    }

    /**
     * A storage stub that answers all three reads the load path makes from one
     * list of events, so a test can state "the store holds these" instead of
     * hand-wiring three expectations that can drift apart.
     *
     * @param DomainEventInterface[] $events
     * @throws Exception
     * @return EventStorageInterface
     */
    private function storageHolding(
        array $events
    ): EventStorageInterface {
        $storage = $this->createStub(EventStorageInterface::class);
        $storage->method('retrieveEvents')->willReturn($events);
        $storage->method('retrieveEventsFromVersion')->willReturnCallback(
            static fn (EntityIdentifierInterface $id, EventVersion $afterVersion): array => array_values(array_filter(
                $events,
                static fn (DomainEventInterface $event): bool => $event->getVersion()->toInt() > $afterVersion->toInt()
            ))
        );
        $storage->method('getCurrentMaxVersion')->willReturn(
            $events === []
                ? EventVersion::unassigned()
                : EventVersion::fromInt(max(array_map(
                    static fn (DomainEventInterface $event): int => $event->getVersion()->toInt(),
                    $events
                )))
        );

        return $storage;
    }

    /**
     * The whole point of a snapshot is that the events it already accounts for
     * never have to be read, let alone hydrated through reflection. Loading the
     * full stream and filtering afterwards — as this used to — makes a snapshot
     * cost more than it saves.
     *
     * @throws Exception|ReflectionException|DateMalformedStringException
     */
    public function test_load_withASnapshotAsksOnlyForTheEventsAfterIt(): void
    {
        $aggregateId = EntityIdentifier::fromString('agg-tail');
        $snapshotVersion = EventVersion::fromInt(3);

        $snapshots = $this->createStub(SnapshotStorageInterface::class);
        $snapshots->method('retrieveSnapshot')->willReturn(
            new GenericSnapshot($aggregateId, $snapshotVersion, ['state' => 'x'], OccurredOn::now())
        );

        $storage = $this->createMock(EventStorageInterface::class);
        $storage->expects($this->never())
            ->method('retrieveEvents');
        $storage->method('getCurrentMaxVersion')->willReturn(EventVersion::fromInt(4));
        $storage->expects($this->once())
            ->method('retrieveEventsFromVersion')
            ->with($aggregateId, $snapshotVersion)
            ->willReturn([new RealDummyEvent('agg-tail', 4)]);

        $factory = $this->createStub(SnapshotFactoryInterface::class);
        $factory->method('createFromStorage')->willReturn(new DummySnapshot());

        $repo = new AggregateRepository($storage, $snapshots, $factory);

        /** @var TestSnapshotableAggregate $aggregate */
        $aggregate = $repo->load(TestSnapshotableAggregate::class, $aggregateId);

        $this->assertTrue($aggregate->snapshotApplied);
        $this->assertCount(
            1,
            TestSnapshotableAggregate::$appliedEvents,
            'Only the events the snapshot does not already account for may be replayed.'
        );
    }

    /**
     * @throws Exception|ReflectionException|DateMalformedStringException
     */
    public function test_loadWithoutSnapshot(): void
    {
        $event = new RealDummyEvent('agg-1', 1);

        $storage = $this->createStub(EventStorageInterface::class);
        $storage->method('retrieveEvents')->willReturn([$event]);

        $repo = new AggregateRepository($storage);
        $result = $repo->load(DummyAggregate::class, EntityIdentifier::fromString('agg-1'));

        $this->assertInstanceOf(DummyAggregate::class, $result);
    }

    /**
     * @throws Exception
     */
    public function test_saveSkipsStorageWhenNoEvents(): void
    {
        $aggregate = new DummyAggregate([]);

        $storage = $this->createMock(EventStorageInterface::class);
        $storage->expects($this->never())->method('storeEvents');

        $repo = new AggregateRepository($storage);
        $repo->save($aggregate);
    }

    /**
     * @throws Exception
     */
    public function test_createSnapshotReturnsSnapshot(): void
    {
        $aggregate = new DummySnapshotableAggregate();

        $factory = $this->createMock(SnapshotFactoryInterface::class);
        $snapshot = $this->createStub(SnapshotInterface::class);
        $factory->expects($this->once())
            ->method('createFromStorage')
            ->with(
                DummySnapshot::class,
                $this->isInstanceOf(EntityIdentifierInterface::class),
                $this->isInstanceOf(EventVersion::class),
                ['state' => 'data']
            )
            ->willReturn($snapshot);

        $repo = new AggregateRepository($this->createStub(EventStorageInterface::class), null, $factory);
        $result = $repo->createSnapshot($aggregate, DummySnapshot::class);

        $this->assertSame($snapshot, $result);
    }

    /**
     * @throws Exception
     */
    public function test_createSnapshotThrowsForInvalidClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement SnapshotInterface');

        $aggregate = new DummySnapshotableAggregate();

        $factory = $this->createStub(SnapshotFactoryInterface::class);
        $repo = new AggregateRepository($this->createStub(EventStorageInterface::class), null, $factory);

        $repo->createSnapshot($aggregate, stdClass::class);
    }

    /**
     * @throws Exception
     */
    public function test_saveWithSnapshotSkipsIfNotSnapshotable(): void
    {
        $event = $this->createStub(DomainEventInterface::class);
        $aggregate = new DummyAggregate([$event]);

        $storage = $this->createMock(EventStorageInterface::class);
        $storage->expects($this->once())->method('storeEvents')->with([$event]);

        $snapshots = $this->createMock(SnapshotStorageInterface::class);
        $snapshots->expects($this->never())->method('storeSnapshot');

        $repo = new AggregateRepository($storage, $snapshots);
        $repo->saveWithSnapshot($aggregate);
    }

    /**
     * @throws Exception|ReflectionException|DateMalformedStringException
     */
    public function test_loadThrowsForInvalidAggregateClass(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not an AggregateRoot');

        $storage = $this->createStub(EventStorageInterface::class);
        $repo = new AggregateRepository($storage);
        $repo->load(stdClass::class, EntityIdentifier::fromString('invalid'));
    }

    /**
     * @throws ReflectionException|DateMalformedStringException|Exception
     */
    public function test_loadFiltersEventsAfterSnapshotVersion(): void
    {
        $aggregateId = EntityIdentifier::fromString('agg-100');
        $snapshotVersion = 2;

        $snapshot = $this->createStub(SnapshotInterface::class);
        $snapshot->method('getVersion')->willReturn(EventVersion::fromInt($snapshotVersion));

        $event1 = new RealDummyEvent((string) $aggregateId, 2);
        $event2 = new RealDummyEvent((string) $aggregateId, 3);

        $eventStorage = $this->storageHolding([$event1, $event2]);

        $snapshotStorage = $this->createStub(SnapshotStorageInterface::class);
        $snapshotStorage->method('retrieveSnapshot')->willReturn($snapshot);

        TestSnapshotableAggregate::reset();

        $repo = new AggregateRepository($eventStorage, $snapshotStorage);
        $repo->load(DummySnapshotableAggregate::class, $aggregateId);

        $filtered = DummySnapshotableAggregate::$reconstitutedEvents;

        $this->assertCount(1, $filtered);
        $this->assertFalse(isset($filtered[1])); // no new events applied
        $this->assertSame(3, $filtered[0]->getVersion()->toInt());
    }

    /**
     * The stream holds exactly the events the snapshot claims to summarise, so
     * the snapshot is applied and nothing is replayed on top of it. It used to
     * hold none at all — which is the orphaned-cache case
     * `test_load_discardsASnapshotWhoseStreamHasNoEventsAtAll()` now refuses,
     * and asserting the state was seeded from it was asserting the defect.
     *
     * @throws Exception|ReflectionException|DateMalformedStringException
     */
    public function test_loadSeedsAggregateFromSnapshot(): void
    {
        $aggregateId = EntityIdentifier::fromString('agg-seed');
        $snapshotVersion = 5;
        $state = ['foo' => 'bar'];

        $snapshot = $this->createStub(SnapshotInterface::class);
        $snapshot->method('getVersion')->willReturn(EventVersion::fromInt($snapshotVersion));
        $snapshot->method('getState')->willReturn($state);
        $snapshot->method('getOccurredOn')->willReturn(OccurredOn::now());

        $eventStorage = $this->storageHolding([new RealDummyEvent('agg-seed', $snapshotVersion)]);

        $snapshotStorage = $this->createStub(SnapshotStorageInterface::class);
        $snapshotStorage->method('retrieveSnapshot')->willReturn($snapshot);

        $seeded = new class() extends DummyAggregate implements SnapshotableAggregateInterface {
            /**
             * @var array<string, mixed>
             */
            public array $snapshotState = [];
            public function applySnapshot(SnapshotInterface $snapshot): void
            {
                $this->snapshotState = $snapshot->getState();
            }
            public function shouldTakeSnapshot(): bool
            {
                return false;
            }
            public function getSnapshotClass(): string
            {
                return DummySnapshot::class;
            }
            public function getSnapshotState(): array
            {
                return [];
            }
            public function getSnapshotVersion(): EventVersion
            {
                return EventVersion::fromInt(0);
            }
            public function getAggregateId(): EntityIdentifierInterface
            {
                return EntityIdentifier::fromString('seeded');
            }
        };

        $repo = new AggregateRepository($eventStorage, $snapshotStorage);
        $loaded = $repo->load(get_class($seeded), $aggregateId);

        $this->assertSame($state, $loaded->snapshotState);
    }

    /**
     * @throws Exception
     */
    public function test_saveStoresUncommittedEvents(): void
    {
        $event = $this->createStub(DomainEventInterface::class);

        $aggregate = new DummyAggregate([$event]);

        $storage = $this->createMock(EventStorageInterface::class);
        $storage->expects($this->once())->method('storeEvents')->with([$event]);

        $repo = new AggregateRepository($storage);
        $repo->save($aggregate);

        $this->assertEmpty($aggregate->getUncommittedEvents());
    }

    /**
     * @throws Exception
     */
    public function test_saveWithSnapshotExplicit(): void
    {
        $snapshot = $this->createStub(SnapshotInterface::class);
        $aggregate = new DummyAggregate();

        $storage = $this->createStub(EventStorageInterface::class);
        $snapshots = $this->createMock(SnapshotStorageInterface::class);
        $snapshots->expects($this->once())->method('storeSnapshot')->with($snapshot);

        $history = $this->createMock(SnapshotHistoryStorageInterface::class);
        $history->expects($this->once())->method('persistVersioned')->with($snapshot);

        $repo = new AggregateRepository($storage, $snapshots, null, $history);
        $repo->saveWithSnapshot($aggregate, $snapshot);
    }

    /**
     * @throws Exception
     */
    public function test_saveWithAutoGeneratedSnapshot(): void
    {
        $snapshot = $this->createStub(SnapshotInterface::class);

        $aggregate = new DummySnapshotableAggregate();

        $factory = $this->createMock(SnapshotFactoryInterface::class);
        $factory->expects($this->once())->method('createFromStorage')
            ->with(
                DummySnapshot::class,
                $this->isInstanceOf(EntityIdentifierInterface::class),
                $this->isInstanceOf(EventVersion::class),
                ['state' => 'data']
            )
            ->willReturn($snapshot);

        $snapshots = $this->createMock(SnapshotStorageInterface::class);
        $snapshots->expects($this->once())->method('storeSnapshot')->with($snapshot);

        $history = $this->createMock(SnapshotHistoryStorageInterface::class);
        $history->expects($this->once())->method('persistVersioned')->with($snapshot);

        $repo = new AggregateRepository($this->createStub(EventStorageInterface::class), $snapshots, $factory, $history);
        $repo->saveWithSnapshot($aggregate);
    }

    /**
     * @throws Exception
     */
    public function test_deleteCallsAllStorages(): void
    {
        $aggregateId = EntityIdentifier::fromString('agg-123');

        $eventStorage = $this->createMock(EventStorageInterface::class);
        $eventStorage->expects($this->once())
            ->method('deleteEvents')
            ->with($aggregateId);

        $snapshotStorage = $this->createStub(SnapshotStorageInterface::class);

        $snapshotHistory = $this->createMock(SnapshotHistoryStorageInterface::class);
        $snapshotHistory->expects($this->once())
            ->method('deleteAll')
            ->with($aggregateId);

        $repo = new AggregateRepository(
            $eventStorage,
            $snapshotStorage,
            null,
            $snapshotHistory
        );

        $repo->delete($aggregateId);
    }

    /**
     * @throws ReflectionException|Exception
     */
    public function test_generateSnapshotReturnsNullIfAggregateIsNotSnapshotable(): void
    {
        $aggregate = new DummyAggregate();

        $repo = new AggregateRepository(
            $this->createStub(EventStorageInterface::class),
            null,
            $this->createStub(SnapshotFactoryInterface::class)
        );

        $result = $this->invokeGenerateSnapshotFromAggregate($repo, $aggregate);
        $this->assertNull($result);
    }

    /**
     * @throws ReflectionException|Exception
     */
    public function test_generateSnapshotThrowsIfSnapshotClassIsInvalid(): void
    {
        $aggregate = new DummyInvalidSnapshotAggregate();

        $repo = new AggregateRepository(
            $this->createStub(EventStorageInterface::class),
            null,
            $this->createStub(SnapshotFactoryInterface::class)
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement SnapshotInterface');

        $this->invokeGenerateSnapshotFromAggregate($repo, $aggregate);
    }

    /**
     * @throws Exception
     */
    public function test_deleteRemovesEventsSnapshotAndHistory(): void
    {
        $aggregateId = EntityIdentifier::fromString('agg-del');

        $eventStorage = $this->createMock(EventStorageInterface::class);
        $eventStorage->expects($this->once())->method('deleteEvents')->with($aggregateId);

        $snapshotStorage = $this->createMock(SnapshotStorageInterface::class);
        $snapshotStorage->expects($this->once())->method('deleteSnapshot')->with($aggregateId);

        $snapshotHistory = $this->createMock(SnapshotHistoryStorageInterface::class);
        $snapshotHistory->expects($this->once())->method('deleteAll')->with($aggregateId);

        $repo = new AggregateRepository(
            $eventStorage,
            $snapshotStorage,
            null,
            $snapshotHistory
        );

        $repo->delete($aggregateId);
    }

    /**
     * The regression this pins: delete() used to guard the snapshot cleanup
     * with `instanceof SnapshotHistoryStorageInterface`, which is false for
     * every shipped SnapshotStorageInterface implementation. A deleted
     * aggregate came straight back from the leftover snapshot on next load.
     *
     * @throws Exception
     */
    public function test_deleteRemovesTheSnapshotOfAPlainSnapshotStorage(): void
    {
        $aggregateId = EntityIdentifier::fromString('agg-del-plain');

        $snapshotStorage = new InMemorySnapshotStorage();
        $snapshotStorage->storeSnapshot(new GenericSnapshot(
            $aggregateId,
            EventVersion::fromInt(2),
            ['balance' => 42],
            OccurredOn::now()
        ));

        $eventStorage = $this->createMock(EventStorageInterface::class);
        $eventStorage->expects($this->once())->method('deleteEvents')->with($aggregateId);

        $repo = new AggregateRepository($eventStorage, $snapshotStorage);
        $repo->delete($aggregateId);

        $this->assertNull($snapshotStorage->retrieveSnapshot($aggregateId));
    }

    /**
     * @throws Exception
     */
    public function test_createSnapshotReturnsNullIfFactoryNotProvided(): void
    {
        $aggregate = new DummySnapshotableAggregate();

        $repo = new AggregateRepository(
            $this->createStub(EventStorageInterface::class),
            null,
            null
        );

        $result = $repo->createSnapshot($aggregate, DummySnapshot::class);
        $this->assertNull($result);
    }

    /**
     * @throws ReflectionException
     */
    private function invokeGenerateSnapshotFromAggregate(AggregateRepository $repo, AggregateRoot $aggregate): mixed
    {
        $method = new ReflectionMethod($repo, 'generateSnapshotFromAggregate');

        return $method->invoke($repo, $aggregate);
    }

    /**
     * @throws ReflectionException|Exception
     */
    public function test_ensureAggregateClassThrowsForInvalidClass(): void
    {
        $repo = new AggregateRepository($this->createStub(EventStorageInterface::class));
        $method = new ReflectionMethod($repo, 'ensureAggregateClass');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not an AggregateRoot');
        $method->invoke($repo, stdClass::class);
    }

    /**
     * @throws ReflectionException|Exception
     */
    public function test_toEntries(): void
    {
        $repo = new AggregateRepository($this->createStub(EventStorageInterface::class));
        $method = new ReflectionMethod($repo, 'toEntries');

        $event = new RealDummyEvent('agg-test', 5);
        $entries = $method->invoke($repo, [$event]);

        $this->assertIsArray($entries);
        $this->assertCount(1, $entries);
        $this->assertInstanceOf(EventEntry::class, $entries[0]);
    }

    /**
     * @throws ReflectionException|Exception
     */
    public function test_loadFromEvents(): void
    {
        $repo = new AggregateRepository($this->createStub(EventStorageInterface::class));
        $method = new ReflectionMethod($repo, 'loadFromEvents');

        $event = new RealDummyEvent('agg-2', 1);
        $result = $method->invoke($repo, DummyAggregate::class, [$event]);

        $this->assertInstanceOf(DummyAggregate::class, $result);
    }

    /**
     * The storage hands back domain events it has already reconstructed —
     * through the event factory and the upcaster it was built with, and
     * through the payload migration. The repository used to serialise each one
     * with `toArray()` and hydrate the copy, so what the aggregate replayed
     * was a reflection-built lookalike: a factory-only event rebuilt without
     * its factory, a payload migrated a second time, and every field
     * `toArray()` does not expose silently gone.
     *
     * Asserted as identity, because that is the claim — the aggregate replays
     * the events the store returned, not copies of them.
     *
     * @throws ReflectionException|Exception|DateMalformedStringException
     */
    public function test_load_replaysTheEventsTheStorageReconstructedRatherThanCopiesOfThem(): void
    {
        $first = new RealDummyEvent('agg-identity', 1);
        $second = new RealDummyEvent('agg-identity', 2);

        $repo = new AggregateRepository($this->storageHolding([$first, $second]));

        /** @var ReplayingAggregate $aggregate */
        $aggregate = $repo->load(ReplayingAggregate::class, EntityIdentifier::fromString('agg-identity'));

        $this->assertSame([$first, $second], $aggregate->replayed, 'The aggregate replayed rebuilt copies.');
        $this->assertSame(2, $aggregate->getAggregateVersion()->toInt());
    }

    /**
     * @throws ReflectionException|Exception
     */
    public function test_loadFromSnapshot(): void
    {
        $aggregateId = EntityIdentifier::fromString('agg-3');
        $repo = new AggregateRepository($this->storageHolding([
            new RealDummyEvent('agg-3', 1),
            new RealDummyEvent('agg-3', 2),
        ]));
        $method = new ReflectionMethod($repo, 'loadFromSnapshot');

        $snapshot = $this->createStub(SnapshotInterface::class);
        $snapshot->method('getVersion')->willReturn(EventVersion::fromInt(1));

        $result = $method->invoke($repo, DummySnapshotableAggregate::class, $snapshot, $aggregateId);

        $this->assertInstanceOf(DummySnapshotableAggregate::class, $result);
        $this->assertCount(1, DummySnapshotableAggregate::$reconstitutedEvents);
        $this->assertSame(2, DummySnapshotableAggregate::$reconstitutedEvents[0]->getVersion()->toInt());
    }

    /**
     * @throws ReflectionException|Exception
     */
    public function test_loadFromSnapshot_appliesFilteredEventsAfterApplyingSnapshot(): void
    {
        $aggregateId = EntityIdentifier::fromString('agg-x');
        $repo = new AggregateRepository($this->storageHolding([
            new RealDummyEvent('agg-x', 2),
            new RealDummyEvent('agg-x', 3),
            new RealDummyEvent('agg-x', 3),
        ]));
        $method = new ReflectionMethod($repo, 'loadFromSnapshot');

        $snapshot = $this->createStub(SnapshotInterface::class);
        $snapshot->method('getVersion')->willReturn(EventVersion::fromInt(1));

        /** @var TestSnapshotableAggregate $aggregate */
        $aggregate = $method->invoke($repo, TestSnapshotableAggregate::class, $snapshot, $aggregateId);

        $this->assertInstanceOf(TestSnapshotableAggregate::class, $aggregate);
        $this->assertCount(3, TestSnapshotableAggregate::$appliedEvents);
        $this->assertSame(2, TestSnapshotableAggregate::$appliedEvents[0]->getVersion()->toInt());
        $this->assertSame(3, TestSnapshotableAggregate::$appliedEvents[1]->getVersion()->toInt());
        $this->assertTrue($aggregate->snapshotApplied);
    }

    /**
     * @throws ReflectionException|Exception
     */
    public function test_loadFromSnapshot_filtersOutEventsUpToSnapshotVersion(): void
    {
        $aggregateId = EntityIdentifier::fromString('agg-y');
        $repo = new AggregateRepository($this->storageHolding([
            new RealDummyEvent('agg-y', 2),
            new RealDummyEvent('agg-y', 3),
        ]));
        $method = new ReflectionMethod($repo, 'loadFromSnapshot');

        $snapshot = $this->createStub(SnapshotInterface::class);
        $snapshot->method('getVersion')->willReturn(EventVersion::fromInt(3));

        /** @var TestSnapshotableAggregate $aggregate */
        $aggregate = $method->invoke($repo, TestSnapshotableAggregate::class, $snapshot, $aggregateId);

        $this->assertInstanceOf(TestSnapshotableAggregate::class, $aggregate);
        $this->assertCount(0, TestSnapshotableAggregate::$appliedEvents);
        $this->assertFalse(isset(TestSnapshotableAggregate::$appliedEvents[0]));
        $this->assertFalse(isset(TestSnapshotableAggregate::$appliedEvents[1]));
        $this->assertTrue($aggregate->snapshotApplied);
    }

    /**
     * A snapshot exists but the aggregate cannot consume one. Falling back to a
     * full replay costs time; returning the freshly built, empty aggregate — as
     * this used to — silently hands the caller state that never existed.
     *
     * @throws ReflectionException|Exception
     */
    public function test_loadFromSnapshot_fallsBackToFullReplayForANonSnapshotableAggregate(): void
    {
        $repo = new AggregateRepository($this->storageHolding([]));
        $method = new ReflectionMethod($repo, 'loadFromSnapshot');

        $snapshot = $this->createStub(SnapshotInterface::class);
        $snapshot->method('getVersion')->willReturn(EventVersion::fromInt(1));

        $aggregate = $method->invoke($repo, DummyAggregate::class, $snapshot, EntityIdentifier::fromString('agg-plain'));

        $this->assertInstanceOf(DummyAggregate::class, $aggregate);
    }

    /**
     * Storage adapters hand back a GenericSnapshot whatever was written, so the
     * aggregate's own getSnapshotClass() is what decides the type its
     * applySnapshot() receives.
     *
     * @throws ReflectionException|Exception
     */
    public function test_loadFromSnapshot_rebuildsTheSnapshotAsTheClassTheAggregateDeclares(): void
    {
        $stored = new GenericSnapshot(
            EntityIdentifier::fromString('agg-declared'),
            EventVersion::fromInt(1),
            ['state' => 'data'],
            OccurredOn::now()
        );

        $rebuilt = $this->createStub(SnapshotInterface::class);

        $factory = $this->createMock(SnapshotFactoryInterface::class);
        $factory->expects($this->once())
            ->method('createFromStorage')
            ->with(
                DummySnapshot::class,
                $this->isInstanceOf(EntityIdentifierInterface::class),
                $this->isInstanceOf(EventVersion::class),
                ['state' => 'data']
            )
            ->willReturn($rebuilt);

        $repo = new AggregateRepository(
            $this->storageHolding([new RealDummyEvent('agg-declared', 1)]),
            null,
            $factory
        );
        $method = new ReflectionMethod($repo, 'loadFromSnapshot');

        /** @var TestSnapshotableAggregate $aggregate */
        $aggregate = $method->invoke($repo, TestSnapshotableAggregate::class, $stored, EntityIdentifier::fromString('agg-declared'));

        $this->assertTrue($aggregate->snapshotApplied);
    }

    /**
     * A snapshot claiming a version beyond the stream's own maximum is stale or
     * was written with a wrong version. Trusting it would filter out events it
     * does not actually contain, so the repository discards it and replays the
     * stream in full instead.
     *
     * @throws ReflectionException|Exception
     */
    public function test_loadFromSnapshot_fallsBackToFullReplayWhenTheSnapshotVersionExceedsTheStream(): void
    {
        $repo = new AggregateRepository($this->storageHolding([
            new RealDummyEvent('agg-y', 2),
            new RealDummyEvent('agg-y', 3),
        ]));
        $method = new ReflectionMethod($repo, 'loadFromSnapshot');

        $snapshot = $this->createStub(SnapshotInterface::class);
        $snapshot->method('getVersion')->willReturn(EventVersion::fromInt(99));

        /** @var TestSnapshotableAggregate $aggregate */
        $aggregate = $method->invoke($repo, TestSnapshotableAggregate::class, $snapshot, EntityIdentifier::fromString('agg-y'));

        $this->assertFalse($aggregate->snapshotApplied, 'An implausible snapshot must not be applied.');
    }

    /**
     * A snapshot is a cache of a replay, never a source of facts on its own.
     * An empty stream beside a snapshot claiming version 9 is the shape of a
     * cache that outlived the events it summarised — a stream deleted for
     * retention or erasure, a snapshot written against a store that was later
     * replaced, a wrong aggregate id. Trusting it makes the cache the only
     * truth there is, and the aggregate carries on from version 9 with events
     * 1 to 9 permanently missing underneath it.
     *
     * "Nothing contradicts it" was the old reading of an empty stream. An
     * empty stream contradicts every snapshot that claims to summarise
     * something: there is nothing there to summarise.
     *
     * @throws ReflectionException|Exception|DateMalformedStringException
     */
    public function test_load_discardsASnapshotWhoseStreamHasNoEventsAtAll(): void
    {
        $aggregateId = EntityIdentifier::fromString('agg-orphaned-snapshot');

        $snapshots = $this->createStub(SnapshotStorageInterface::class);
        $snapshots->method('retrieveSnapshot')->willReturn(
            new GenericSnapshot($aggregateId, EventVersion::fromInt(9), ['state' => 'x'], OccurredOn::now())
        );

        $repo = new AggregateRepository($this->storageHolding([]), $snapshots);

        /** @var TestSnapshotableAggregate $aggregate */
        $aggregate = $repo->load(TestSnapshotableAggregate::class, $aggregateId);

        $this->assertFalse(
            $aggregate->snapshotApplied,
            'A snapshot claiming events that the stream does not have was treated as the truth.'
        );
        $this->assertSame(
            0,
            $aggregate->getAggregateVersion()->toInt(),
            'An aggregate with no events must not come back carrying a version.'
        );
    }

    /**
     * The boundary on the other side: a snapshot that claims no events is
     * consistent with a stream that has none, so it is applied. Rejecting it
     * too would be rejecting an agreement.
     *
     * @throws ReflectionException|Exception|DateMalformedStringException
     */
    public function test_load_keepsAVersionlessSnapshotOfAnEmptyStream(): void
    {
        $aggregateId = EntityIdentifier::fromString('agg-fresh-snapshot');

        $snapshots = $this->createStub(SnapshotStorageInterface::class);
        $snapshots->method('retrieveSnapshot')->willReturn(
            new GenericSnapshot($aggregateId, EventVersion::unassigned(), ['state' => 'x'], OccurredOn::now())
        );

        $repo = new AggregateRepository($this->storageHolding([]), $snapshots);

        /** @var TestSnapshotableAggregate $aggregate */
        $aggregate = $repo->load(TestSnapshotableAggregate::class, $aggregateId);

        $this->assertTrue($aggregate->snapshotApplied);
    }

    /**
     * @throws ReflectionException|Exception
     */
    public function test_loadFromSnapshot_filtersOutEventsUpToSnapshotVersionSecond(): void
    {
        $repo = new AggregateRepository($this->storageHolding([
            new RealDummyEvent('agg-y', 2),
            new RealDummyEvent('agg-y', 3),
        ]));
        $method = new ReflectionMethod($repo, 'loadFromSnapshot');

        $snapshot = $this->createStub(SnapshotInterface::class);
        $snapshot->method('getVersion')->willReturn(EventVersion::fromInt(0));

        /** @var TestSnapshotableAggregate $aggregate */
        $aggregate = $method->invoke($repo, TestSnapshotableAggregate::class, $snapshot, EntityIdentifier::fromString('agg-y'));

        $this->assertInstanceOf(TestSnapshotableAggregate::class, $aggregate);
        $this->assertCount(2, TestSnapshotableAggregate::$appliedEvents);
        $this->assertSame(2, TestSnapshotableAggregate::$appliedEvents[0]->getVersion()->toInt());
        $this->assertSame(3, TestSnapshotableAggregate::$appliedEvents[1]->getVersion()->toInt());
        $this->assertTrue($aggregate->snapshotApplied);
    }

}

final class TestSnapshotableAggregate extends AggregateRoot implements SnapshotableAggregateInterface
{
    public static array $appliedEvents = [];
    public bool $snapshotApplied = false;

    public static function reset(): void
    {
        self::$appliedEvents = [];
    }

    public static function reconstitute(
        EventStream $stream
    ): static {
        return new static();
    }

    public function applySnapshot(
        SnapshotInterface $snapshot
    ): void {
        $this->snapshotApplied = true;
    }

    public function applyEvent(
        DomainEventInterface $event,
        bool $isNew = true
    ): void {
        self::$appliedEvents[] = $event;
    }

    public function shouldTakeSnapshot(): bool
    {
        return false;
    }

    public function getSnapshotClass(): string
    {
        return DummySnapshot::class;
    }

    public function getSnapshotState(): array
    {
        return [];
    }

    public function getSnapshotVersion(): EventVersion
    {
        return EventVersion::fromInt(0);
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString('test-snapshotable');
    }

    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }
}

class DummyAggregate extends AggregateRoot
{
    public static array $reconstitutedEvents = [];

    public function __construct(
        array $events = []
    ) {
        foreach ($events as $event) {
            $this->applyEvent($event);
        }
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public static function reset(): void
    {
        self::$reconstitutedEvents = [];
    }

    public static function reconstitute(
        EventStream $stream
    ): static {
        return new static();
    }

    public function applyEvent(
        DomainEventInterface $event,
        bool $isNew = true
    ): void {
        self::$reconstitutedEvents[] = $event;

        if ($isNew) {
            $this->uncommittedEvents[] = $event;
        }
    }

    public function getUncommittedEvents(): array
    {
        return $this->uncommittedEvents;
    }

    public function clearUncommittedEvents(): void
    {
        $this->uncommittedEvents = [];
    }

    protected array $uncommittedEvents = [];
}

final class DummySnapshotableAggregate extends DummyAggregate implements SnapshotableAggregateInterface
{
    public static array $reconstitutedEvents = [];

    public static function reset(): void
    {
        self::$reconstitutedEvents = [];
    }

    public function applySnapshot(
        SnapshotInterface $snapshot
    ): void {
    }

    public function applyEvent(
        DomainEventInterface $event,
        bool $isNew = true
    ): void {
        self::$reconstitutedEvents[] = $event;

        if ($isNew) {
            $this->uncommittedEvents[] = $event;
        }
    }

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
        return ['state' => 'data'];
    }

    public function getSnapshotVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString('repo-dummy-snapshotable');
    }
}

final class DummySnapshot implements SnapshotInterface
{
    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString('dummy');
    }
    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }
    public function getState(): array
    {
        return ['foo' => 'bar'];
    }
    public function getOccurredOn(): OccurredOn
    {
        return OccurredOn::now();
    }
}

/**
 * Replays through `AggregateRoot::reconstitute()` rather than overriding it,
 * so what the repository hands the base class is what gets recorded.
 */
final class ReplayingAggregate extends AggregateRoot
{
    /** @var DomainEventInterface[] */
    public array $replayed = [];

    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function applyEvent(
        DomainEventInterface $event,
        bool $isNew = true
    ): void {
        $this->replayed[] = $event;

        parent::applyEvent($event, $isNew);
    }
}

final class RealDummyEvent implements DomainEventInterface
{
    use HasEventMetadata;

    private DateTimeImmutable $occurredOn;
    protected EventVersion $version;

    public function __construct(
        private readonly string $aggregateId,
        int $version,
        ?DateTimeImmutable $occurredOn = null
    ) {
        $this->occurredOn = $occurredOn ?? new DateTimeImmutable();
        $this->version = EventVersion::fromInt($version);
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString($this->aggregateId);
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function toArray(): array
    {
        return [
            'aggregateId' => $this->aggregateId,
            'version' => $this->version->toInt(),
            'occurredOn' => $this->occurredOn->format('Y-m-d H:i:s.u'),
        ];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class DummyInvalidSnapshotAggregate extends AggregateRoot implements SnapshotableAggregateInterface
{
    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function shouldTakeSnapshot(): bool
    {
        return true;
    }

    public function getSnapshotClass(): string
    {
        return stdClass::class;
    }

    public function getSnapshotState(): array
    {
        return [];
    }

    public function getSnapshotVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString('dummy-invalid');
    }

    public function applySnapshot(
        SnapshotInterface $snapshot
    ): void {
    }
}

class DualSnapshotStorage implements SnapshotStorageInterface, SnapshotHistoryStorageInterface
{
    public function storeSnapshot(
        SnapshotInterface $snapshot
    ): void {
    }

    public function retrieveSnapshot(
        EntityIdentifierInterface $aggregateId
    ): ?SnapshotInterface {
        return null;
    }

    public function deleteSnapshot(
        EntityIdentifierInterface $aggregateId
    ): void {
    }

    public function deleteAll(
        EntityIdentifierInterface $aggregateId
    ): void {
    }

    public function persistVersioned(
        SnapshotInterface $snapshot
    ): void {
    }

    public function retrieveAll(
        EntityIdentifierInterface $aggregateId
    ): array {
        return [];
    }

    public function deleteSingle(
        EntityIdentifierInterface $aggregateId,
        int $version
    ): void {
    }
}
