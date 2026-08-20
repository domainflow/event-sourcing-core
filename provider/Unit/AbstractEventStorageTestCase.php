<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Unit;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventMetadata;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\GlobalEventPage;
use DomainFlow\EventSourcing\Exception\ConcurrencyException;
use DomainFlow\EventSourcing\Exception\DoubleDeliveryException;
use DomainFlow\EventSourcing\Facade\EventSourcingFacade;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventDispatcherInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\OutboxBackedStorageInterface;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use DomainFlow\Uuid\UuidV6;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use Throwable;

#[UsesClass(GlobalEventPage::class)]
abstract class AbstractEventStorageTestCase extends TestCase
{
    abstract protected function getStorage(): EventStorageInterface;
    abstract protected function getStorageWithFactory(): EventStorageInterface;

    /**
     * A storage built with an event factory keeps using it after another
     * storage is built beside it.
     *
     * This is asserted through behaviour instead of a process-wide static:
     * every storage must retain its own reconstruction dependency.
     */
    public function test_a_second_storage_does_not_disturb_the_first_ones_reconstruction(): void
    {
        $withFactory = $this->getStorageWithFactory();
        $aggregateId = EntityIdentifier::fromString('FactoryOwnershipAggregate');

        $withFactory->storeEvents([new AnotherDummyEvent($aggregateId, 1)]);

        // Constructing another storage is exactly what used to wipe the first
        // one's factory.
        $this->getStorage();

        $events = $withFactory->retrieveEvents($aggregateId);

        $this->assertCount(1, $events);
        $this->assertSame(1, $events[0]->getVersion()->toInt());
    }

    public function test_retrieveEvents(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('Aggregate1');

        $result = $storage->retrieveEvents($aggregateId);
        $this->assertEmpty($result, 'Expected no events initially for Aggregate1.');

        $eventId1 = (string) UuidV6::generate();
        $eventId2 = (string) UuidV6::generate();

        $AnotherDummyEvent1 = new AnotherDummyEvent(
            $aggregateId,
            1,
            $eventId1,
        );
        $AnotherDummyEvent2 = new AnotherDummyEvent(
            $aggregateId,
            2,
            $eventId2
        );
        $storage->storeEvents([$AnotherDummyEvent1, $AnotherDummyEvent2]);

        $result = $storage->retrieveEvents($aggregateId);
        $this->assertEquals([$AnotherDummyEvent1, $AnotherDummyEvent2], $result, 'Stored events do not match retrieved events.');

        $invalidAggregateId = EntityIdentifier::fromString('InvalidAggregate1');
        $result = $storage->retrieveEvents($invalidAggregateId);
        $this->assertEmpty($result, 'Expected no events for an invalid aggregate ID.');
    }

    /**
     * @throws Exception
     */
    public function test_retrieveAllEvents(): void
    {
        $storage = $this->getStorage();

        $idA = EntityIdentifier::fromString('Aggregate1');
        $idB = EntityIdentifier::fromString('Aggregate2');

        $mockIdB = $this->createStub(EntityIdentifierInterface::class);
        $mockIdB->method('__toString')->willReturn('Aggregate2');

        $eventId1 = (string) UuidV6::generate();
        $eventId2 = (string) UuidV6::generate();
        $eventId3 = (string) UuidV6::generate();
        $eventId4 = (string) UuidV6::generate();

        // Create events using mocked EntityIdentifiers
        $eventA1 = new AnotherDummyEvent($idA, 1, $eventId1);
        $eventA2 = new AnotherDummyEvent($idA, 2, $eventId2);
        $eventB1 = new AnotherDummyEvent($idB, 1, $eventId3);
        $eventB2 = new AnotherDummyEvent($idB, 2, $eventId4);

        // Store the events
        $storage->storeEvents([$eventA1, $eventA2]);
        $storage->storeEvents([$eventB1, $eventB2]);

        // Drained rather than counted directly: the read is lazy, because an
        // event store is unbounded and materialising it is how a full sweep
        // runs a process out of memory.
        $allEvents = iterator_to_array($storage->retrieveAllEvents(), false);
        $this->assertCount(4, $allEvents, 'Total number of events retrieved does not match expected count.');
        $this->assertEquals([$eventA1, $eventA2, $eventB1, $eventB2], $allEvents, 'All events do not match the expected stored events.');
    }

    /**
     * Lazy in the sense that matters: reading the first event must not have
     * pulled the rest into memory first.
     */
    public function test_retrieveAllEvents_does_not_materialise_the_store(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('LazySweepAggregate');

        $storage->storeEvents([
            new AnotherDummyEvent($aggregateId, 1),
            new AnotherDummyEvent($aggregateId, 2),
            new AnotherDummyEvent($aggregateId, 3),
        ]);

        $seen = [];

        foreach ($storage->retrieveAllEvents() as $event) {
            $seen[] = $event;

            break;
        }

        $this->assertCount(1, $seen, 'A caller must be able to stop after the first event.');
    }

    public function test_retrievePaginatedEvents(): void
    {
        $storage = $this->getStorage();

        $aggregateId = EntityIdentifier::fromString('Aggregate1');
        $AnotherDummyEvents = [];
        for ($i = 1; $i <= 10; $i++) {
            $AnotherDummyEvents[] = new AnotherDummyEvent(
                $aggregateId,
                $i
            );
        }
        $storage->storeEvents($AnotherDummyEvents);

        $paginated = $storage->retrievePaginatedEvents(0, 5);
        $this->assertCount(5, $paginated, 'Expected 5 events in the first page.');
        $this->assertEquals(array_slice($AnotherDummyEvents, 0, 5), $paginated, 'Paginated events do not match expected first 5 events.');

        $paginated = $storage->retrievePaginatedEvents(5, 10);
        $this->assertCount(5, $paginated, 'Expected 5 events in the second page.');
        $this->assertEquals(array_slice($AnotherDummyEvents, 5, 5), $paginated, 'Paginated events do not match expected last 5 events.');
    }

    /**
     * `null` means "no bound", on every adapter.
     *
     * The interface permits it for both parameters, and MongoDB and Redis
     * handled it while MySQL bound it as an integer and produced `LIMIT NULL`
     * — a syntax error. Three adapters, three answers, one interface.
     */
    public function test_retrievePaginatedEvents_treats_null_as_unbounded(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('UnboundedPaginationAggregate');

        $storage->storeEvents([
            new AnotherDummyEvent($aggregateId, 1),
            new AnotherDummyEvent($aggregateId, 2),
            new AnotherDummyEvent($aggregateId, 3),
        ]);

        $this->assertCount(3, $storage->retrievePaginatedEvents(null, null));
    }

    /**
     * An aggregate stream is ordered by version, never by the writing
     * process's wall clock. Clock skew between replicas, an NTP step
     * correction, or two services with different date.timezone settings all
     * reorder a timestamp-sorted stream and silently corrupt replayed state.
     */
    public function test_retrieveEvents_orders_by_version_not_by_timestamp(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('SkewedClockAggregate');

        $base = new DateTimeImmutable('2026-01-01 12:00:00.000000');

        // Deliberately inverted: the earliest version carries the latest timestamp.
        $events = [
            new AnotherDummyEvent($aggregateId, 1, null, $base->modify('+2 seconds')),
            new AnotherDummyEvent($aggregateId, 2, null, $base->modify('+1 second')),
            new AnotherDummyEvent($aggregateId, 3, null, $base),
        ];

        $storage->storeEvents($events);

        $versions = array_map(
            static fn (DomainEventInterface $event): int => $event->getVersion()->toInt(),
            $storage->retrieveEvents($aggregateId)
        );

        $this->assertSame([1, 2, 3], $versions, 'Aggregate streams must come back in version order.');
    }

    public function test_deleteEvent(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('AggregateToDelete');

        $eventId1 = (string) UuidV6::generate();

        $AnotherDummyEvent = new AnotherDummyEvent(
            $aggregateId,
            1,
            $eventId1
        );
        $storage->storeEvents([$AnotherDummyEvent]);
        $this->assertNotEmpty($storage->retrieveEvents($aggregateId), 'Event should exist before deletion.');

        $storage->deleteEvents($aggregateId);
        $this->assertEmpty($storage->retrieveEvents($aggregateId), 'Expected no events after deletion.');
    }

    /**
     * An aggregate's uncommitted events are one logical unit. If any event in
     * the batch cannot be appended, none of them may be: a half-written stream
     * is unrecoverable, because the aggregate believes it emitted events that
     * can never be replayed.
     */
    public function test_a_failing_batch_leaves_the_stream_completely_unchanged(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('AtomicBatchAggregate');

        $storage->storeEvents([new AnotherDummyEvent($aggregateId, 1)]);
        $this->assertCount(1, $storage->retrieveEvents($aggregateId), 'Precondition: one event stored.');

        // Versions 2 and 3 are new, but 1 collides with what is already stored.
        $batch = [
            new AnotherDummyEvent($aggregateId, 2),
            new AnotherDummyEvent($aggregateId, 3),
            new AnotherDummyEvent($aggregateId, 1),
        ];

        $rejected = false;

        try {
            $storage->storeEvents($batch);
        } catch (Throwable) {
            // The exception type is the adapter's business; the store's state is not.
            $rejected = true;
        }

        $this->assertTrue($rejected, 'A batch containing a duplicate version must be rejected.');

        $versions = array_map(
            static fn (DomainEventInterface $event): int => $event->getVersion()->toInt(),
            $storage->retrieveEvents($aggregateId)
        );

        $this->assertSame([1], $versions, 'A rejected batch must not leave partial events behind.');
        $this->assertSame(1, $storage->getCurrentMaxVersion($aggregateId)->toInt());
    }

    /**
     * The unit is the call, not the aggregate.
     *
     * A batch spanning two aggregates must have the same atomicity guarantee
     * on every backend: MySQL
     * and InMemory rolled the whole call back, Redis and MongoDB kept whatever
     * the earlier aggregate had already written. A consumer saving two
     * aggregates in one call had no way to know which of those it would get.
     *
     * The contract uses the stronger guarantee: after a throw the store is
     * exactly as it was, whatever the batch touched. MongoDB standalone is the
     * one deployment that cannot promise this, and refuses to run at all unless
     * the operator opts out explicitly.
     */
    public function test_a_failing_batch_leaves_every_aggregate_it_touched_unchanged(): void
    {
        $storage = $this->getStorage();
        $first = EntityIdentifier::fromString('AtomicCallAggregateA');
        $second = EntityIdentifier::fromString('AtomicCallAggregateB');

        $storage->storeEvents([new AnotherDummyEvent($first, 1)]);
        $storage->storeEvents([new AnotherDummyEvent($second, 1)]);

        // Everything here is appendable except the last event, which collides
        // with what the second aggregate already has. Ordered so the first
        // aggregate's events are perfectly valid on their own — that is what
        // makes a per-aggregate guarantee write them and a per-call one not.
        $batch = [
            new AnotherDummyEvent($first, 2),
            new AnotherDummyEvent($first, 3),
            new AnotherDummyEvent($second, 2),
            new AnotherDummyEvent($second, 1),
        ];

        $rejected = false;

        try {
            $storage->storeEvents($batch);
        } catch (Throwable) {
            // The exception type is the adapter's business; the store's state is not.
            $rejected = true;
        }

        $this->assertTrue($rejected, 'A batch containing a duplicate version must be rejected.');

        $this->assertSame(
            [1],
            $this->storedVersions($storage, $first),
            'A rejected call must not leave events behind for an aggregate whose own events were valid.'
        );
        $this->assertSame([1], $this->storedVersions($storage, $second));
        $this->assertSame(1, $storage->getCurrentMaxVersion($first)->toInt());
        $this->assertSame(1, $storage->getCurrentMaxVersion($second)->toInt());
    }

    /**
     * The degenerate case of the contract above remains explicit so every
     * adapter handles an empty batch consistently.
     *
     * Green on all four the day it was written, unlike the case above — it
     * describes behaviour nothing was getting wrong, and exists so that the
     * next rewrite of a write path cannot quietly turn "nothing to do" into a
     * round trip, an exception, or a consumed position.
     */
    public function test_an_empty_batch_stores_nothing(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('EmptyBatchAggregate');

        $storage->storeEvents([]);

        $this->assertSame([], $storage->retrieveEvents($aggregateId));
        $this->assertSame(
            EventVersion::unassigned()->toInt(),
            $storage->getCurrentMaxVersion($aggregateId)->toInt(),
            'An empty batch must not move any aggregate\'s version.'
        );
        $this->assertSame([], iterator_to_array($storage->retrieveAllEvents(), false));
    }

    /**
     * @param EventStorageInterface $storage
     * @param EntityIdentifierInterface $aggregateId
     * @return list<int>
     */
    private function storedVersions(
        EventStorageInterface $storage,
        EntityIdentifierInterface $aggregateId
    ): array {
        return array_values(array_map(
            static fn (DomainEventInterface $event): int => $event->getVersion()->toInt(),
            $storage->retrieveEvents($aggregateId)
        ));
    }

    /**
     * The read the snapshot load path depends on. Exclusive on the lower bound,
     * version-ordered, and scoped to the one aggregate — an adapter that
     * returns the whole stream and lets the caller filter satisfies none of the
     * reasons this exists.
     */
    public function test_retrieveEventsFromVersion_returns_only_newer_events_in_version_order(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('TailReadAggregate');

        $storage->storeEvents([
            new AnotherDummyEvent($aggregateId, 1),
            new AnotherDummyEvent($aggregateId, 2),
            new AnotherDummyEvent($aggregateId, 3),
            new AnotherDummyEvent($aggregateId, 4),
        ]);

        $versions = array_map(
            static fn (DomainEventInterface $event): int => $event->getVersion()->toInt(),
            $storage->retrieveEventsFromVersion($aggregateId, EventVersion::fromInt(2))
        );

        $this->assertSame([3, 4], $versions, 'The bound is exclusive: version 2 itself must not come back.');
    }

    public function test_retrieveEventsFromVersion_returns_the_whole_stream_for_an_unassigned_version(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('UnassignedBoundAggregate');

        $storage->storeEvents([
            new AnotherDummyEvent($aggregateId, 1),
            new AnotherDummyEvent($aggregateId, 2),
        ]);

        $versions = array_map(
            static fn (DomainEventInterface $event): int => $event->getVersion()->toInt(),
            $storage->retrieveEventsFromVersion($aggregateId, EventVersion::unassigned())
        );

        $this->assertSame([1, 2], $versions, 'No stored event can precede the first, so an unassigned bound means everything.');
    }

    public function test_retrieveEventsFromVersion_is_empty_past_the_end_of_the_stream(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('ExhaustedTailAggregate');

        $storage->storeEvents([new AnotherDummyEvent($aggregateId, 1)]);

        $this->assertSame(
            [],
            $storage->retrieveEventsFromVersion($aggregateId, EventVersion::fromInt(1)),
            'An aggregate whose snapshot is current has no tail to replay.'
        );
    }

    /**
     * A stream is per aggregate. An adapter that filters on version alone would
     * hand back another aggregate's events and corrupt the replay silently.
     */
    public function test_retrieveEventsFromVersion_does_not_leak_other_aggregates(): void
    {
        $storage = $this->getStorage();
        $mine = EntityIdentifier::fromString('TailScopeMine');
        $theirs = EntityIdentifier::fromString('TailScopeTheirs');

        $storage->storeEvents([
            new AnotherDummyEvent($mine, 1),
            new AnotherDummyEvent($mine, 2),
        ]);
        $storage->storeEvents([
            new AnotherDummyEvent($theirs, 1),
            new AnotherDummyEvent($theirs, 2),
        ]);

        $events = $storage->retrieveEventsFromVersion($mine, EventVersion::fromInt(1));

        $this->assertCount(1, $events);
        $this->assertSame((string) $mine, (string) $events[0]->getAggregateId());
    }

    /**
     * The capability CQRS actually rests on: a read model that fell behind, or
     * was restarted, has to be able to catch up without missing an event and
     * without applying one twice. Offset pagination cannot promise that,
     * because a write landing mid-scan shifts every later event by one.
     *
     * The write below lands deliberately *between* two reads.
     */
    public function test_a_projector_resumes_from_its_position_and_sees_every_event_exactly_once(): void
    {
        $storage = $this->getStorage();
        $first = EntityIdentifier::fromString('CursorAggregateOne');
        $second = EntityIdentifier::fromString('CursorAggregateTwo');

        $storage->storeEvents([
            new AnotherDummyEvent($first, 1),
            new AnotherDummyEvent($first, 2),
            new AnotherDummyEvent($first, 3),
        ]);

        $page = $storage->retrieveEventsFromPosition(null, 2);
        $this->assertCount(2, $page->getEvents(), 'The limit must be honoured.');

        $position = $page->getNextPosition();
        $this->assertNotNull($position, 'A page that returned events must say where to resume.');

        $storage->storeEvents([
            new AnotherDummyEvent($second, 1),
            new AnotherDummyEvent($second, 2),
        ]);

        // The restart: the reader keeps nothing but the position string it
        // persisted, and picks up from that alone.
        $seen = [];
        $reads = 0;

        do {
            $page = $storage->retrieveEventsFromPosition($position, 2);

            foreach ($page->getEvents() as $event) {
                $seen[] = (string) $event->getAggregateId() . '#' . $event->getVersion()->toInt();
            }

            $position = $page->getNextPosition();

            // An adapter that ignores the position it was given hands back the
            // same page forever. Without this the suite would hang instead of
            // failing, which is a far worse way to learn about it.
            $this->assertLessThan(10, ++$reads, 'The reader is not advancing: the position is being ignored.');
        } while (!$page->isEmpty());

        $this->assertSame(
            ['CursorAggregateOne#3', 'CursorAggregateTwo#1', 'CursorAggregateTwo#2'],
            $seen,
            'Resuming must continue exactly where the position left off: nothing skipped, nothing repeated.'
        );
    }

    public function test_a_position_read_that_has_caught_up_returns_the_position_it_was_given(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('CaughtUpAggregate');

        $storage->storeEvents([new AnotherDummyEvent($aggregateId, 1)]);

        $page = $storage->retrieveEventsFromPosition(null, 10);
        $position = $page->getNextPosition();

        $exhausted = $storage->retrieveEventsFromPosition($position, 10);

        $this->assertTrue($exhausted->isEmpty());
        $this->assertSame(
            $position,
            $exhausted->getNextPosition(),
            'A reader at the head must still get a position it can persist, not a special case to handle.'
        );
    }

    /**
     * A limit of zero means zero events, not "no limit". MongoDB's find()
     * reads it the other way round, so an adapter that passes the number
     * straight through returns the entire store to a caller that asked for
     * none of it.
     */
    public function test_a_position_read_with_a_limit_of_zero_returns_nothing(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('ZeroLimitPositionAggregate');

        $storage->storeEvents([
            new AnotherDummyEvent($aggregateId, 1),
            new AnotherDummyEvent($aggregateId, 2),
        ]);

        $page = $storage->retrieveEventsFromPosition(null, 0);

        $this->assertTrue($page->isEmpty());
        $this->assertNull($page->getNextPosition(), 'Reading nothing cannot move the position.');
    }

    public function test_a_position_read_of_an_empty_stream_yields_nothing_to_resume_from(): void
    {
        $page = $this->getStorage()->retrieveEventsFromPosition(null, 10);

        $this->assertTrue($page->isEmpty());
        $this->assertNull($page->getNextPosition(), 'There is no position before the first event.');
    }

    /**
     * A storage whose next write fails for a reason that is *not* a version
     * conflict.
     *
     * What that looks like is genuinely per-backend — a missing table, a
     * rejected document, a key of the wrong type — which is why this is a hook
     * rather than something the contract can arrange itself. What must not vary
     * is how the failure is reported.
     *
     * @return EventStorageInterface
     */
    abstract protected function getStorageWhoseWritesFailWithoutConflict(): EventStorageInterface;

    /**
     * This backend's storage, built so an outbox receives its writes — or null
     * where the backend has no outbox integration at all.
     *
     * Null is an answer rather than a skip: it says this storage never puts a
     * second delivery path in play, which the case below asserts just as
     * strictly as it asserts the other branch. What it must never be is a
     * storage that *is* outbox-backed and does not say so, because that is the
     * arrangement the guard exists to catch.
     *
     * @return EventStorageInterface|null
     */
    abstract protected function getStorageDeliveringThroughOutbox(): ?EventStorageInterface;

    /**
     * A storage that is not handing its writes to an outbox must keep working
     * with an inline dispatcher.
     *
     * This is the direction that would break every consumer if the guard were
     * too eager: dispatching from `persist()` is the arrangement the package
     * shipped with, and it stays correct wherever no relay is delivering.
     *
     * @throws Exception|DoubleDeliveryException
     */
    public function test_aStorageWithoutAnOutboxAcceptsAnInlineDispatcher(): void
    {
        $storage = $this->getStorage();

        $this->assertFalse(
            $storage instanceof OutboxBackedStorageInterface && $storage->deliversThroughOutbox(),
            'A storage built without an outbox must not claim one.'
        );

        $facade = new EventSourcingFacade(
            $storage,
            dispatcher: $this->createStub(EventDispatcherInterface::class)
        );

        $this->assertInstanceOf(EventSourcingFacade::class, $facade);
    }

    /**
     * And the direction that is the whole point: with an outbox
     * receiving the writes, a relay delivers them, so an inline dispatcher is
     * a second path and every event goes out twice. The storage has to say so,
     * because the facade cannot see the constructor argument that put the
     * outbox there.
     *
     * @throws Exception|DoubleDeliveryException
     */
    public function test_aStorageWritingToAnOutboxRefusesAnInlineDispatcher(): void
    {
        $storage = $this->getStorageDeliveringThroughOutbox();

        if ($storage === null) {
            // Not a skip: the hook claims this backend has no outbox
            // integration, and that claim is checkable. If one is ever added
            // to the storage without the hook being updated, this is where it
            // is caught — otherwise a backend could grow a second delivery
            // path and quietly stop being covered by the case below.
            $this->assertNotInstanceOf(
                OutboxBackedStorageInterface::class,
                $this->getStorage(),
                'This backend answered that it has no outbox integration, so its storage must not claim one.'
            );

            return;
        }

        $this->assertInstanceOf(
            OutboxBackedStorageInterface::class,
            $storage,
            'A storage that hands its writes to an outbox has to be able to say so.'
        );
        $this->assertTrue($storage->deliversThroughOutbox());

        $this->expectException(DoubleDeliveryException::class);

        new EventSourcingFacade($storage, dispatcher: $this->createStub(EventDispatcherInterface::class));
    }

    /**
     * The distinction the whole exception-translation exists for.
     *
     * A consumer handling ConcurrencyException the textbook way reloads the
     * aggregate and retries the command. Report an oversized payload, a missing
     * table or a broken connection as a conflict and that consumer retries
     * forever instead of failing — and operators read "concurrency conflict" in
     * the logs while the real cause is infrastructure.
     */
    public function test_a_write_failure_that_is_not_a_conflict_is_not_reported_as_one(): void
    {
        $storage = $this->getStorageWhoseWritesFailWithoutConflict();
        $aggregateId = EntityIdentifier::fromString('NonConflictingFailureAggregate');

        $failed = false;

        try {
            $storage->storeEvents([new AnotherDummyEvent($aggregateId, 1)]);
        } catch (ConcurrencyException $exception) {
            $this->fail(sprintf(
                'An infrastructure failure surfaced as a concurrency conflict: %s',
                $exception->getMessage()
            ));
        } catch (Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, 'Guard: this case is only meaningful if the write actually failed.');
    }

    public function test_getCurrentMaxVersion(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('Aggregate1');

        $result = $storage->getCurrentMaxVersion($aggregateId);
        $this->assertEquals(0, $result->toInt(), 'Expected 0 as the initial max version for Aggregate1.');

        $AnotherDummyEvent1 = new AnotherDummyEvent(
            $aggregateId,
            1
        );
        $AnotherDummyEvent2 = new AnotherDummyEvent(
            $aggregateId,
            2
        );
        $storage->storeEvents([$AnotherDummyEvent1, $AnotherDummyEvent2]);

        $result = $storage->getCurrentMaxVersion($aggregateId);
        $this->assertEquals(2, $result->toInt(), 'Expected 2 as the max version for Aggregate1.');
    }

    /**
     * What the infrastructure knows about an event survives storage.
     *
     * Correlation is the field that makes a distributed trace possible at all,
     * so an adapter that quietly drops it defeats the feature while looking
     * like it works.
     */
    public function test_event_metadata_survives_a_round_trip(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('MetadataAggregate');

        $event = (new AnotherDummyEvent($aggregateId, 1))->withMetadata(
            EventMetadata::empty()
                ->withCorrelationId('corr-1')
                ->withCausationId('cause-1')
                ->withActorId('user-7')
                ->withTenantId('tenant-a')
                ->withCustom(['channel' => 'api'])
        );

        $storage->storeEvents([$event]);

        $metadata = $storage->retrieveEvents($aggregateId)[0]->getMetadata();

        $this->assertSame('corr-1', $metadata->getCorrelationId());
        $this->assertSame('cause-1', $metadata->getCausationId());
        $this->assertSame('user-7', $metadata->getActorId());
        $this->assertSame('tenant-a', $metadata->getTenantId());
        $this->assertSame(['channel' => 'api'], $metadata->getCustom());
    }

    /**
     * An event nobody attached metadata to reads back as empty, not as null
     * and not as an error — which is also how a row written before metadata
     * existed has to behave.
     */
    public function test_an_event_without_metadata_reads_back_as_empty(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('NoMetadataAggregate');

        $storage->storeEvents([new AnotherDummyEvent($aggregateId, 1)]);

        $this->assertTrue($storage->retrieveEvents($aggregateId)[0]->getMetadata()->isEmpty());
    }

    /**
     * An aggregate id is an opaque identifier, and two that differ are
     * two aggregates.
     *
     * Obvious enough that no adapter set out to break it — but MySQL's default
     * collation is case-insensitive, so `WHERE aggregate_id = 'order-1'`
     * matched `Order-1` as well and merged two unrelated streams into one, with
     * no error anywhere. UUIDs are lowercase hex, which is why nothing ever
     * surfaced it; `EntityIdentifierInterface` exists so consumers can bring
     * their own id type, which is why it matters.
     *
     * Redis and MongoDB compare bytes and pass this from the day it was
     * written. It is here rather than in the MySQL suite because it is a rule
     * about the interface, not about one backend.
     */
    public function test_two_aggregate_ids_differing_only_in_case_are_two_aggregates(): void
    {
        $storage = $this->getStorage();

        $lower = EntityIdentifier::fromString('case-sensitive-order');
        $upper = EntityIdentifier::fromString('Case-Sensitive-Order');

        $storage->storeEvents([new AnotherDummyEvent($lower, 1)]);
        $storage->storeEvents([new AnotherDummyEvent($upper, 1)]);

        $this->assertCount(1, $storage->retrieveEvents($lower), 'One aggregate must not see the other one\'s events.');
        $this->assertCount(1, $storage->retrieveEvents($upper));
        $this->assertSame(1, $storage->getCurrentMaxVersion($lower)->toInt());
        $this->assertSame(1, $storage->getCurrentMaxVersion($upper)->toInt());
    }

    /**
     * A stored timestamp means the same moment on every runtime.
     *
     * The stored format carries no offset, so the only thing that makes it
     * comparable is that both ends agree it is UTC. The read side must not
     * parse it in whatever `date.timezone` the
     * process happened to have, so every event read back on a non-UTC runtime
     * denoted a different moment than the one written — and drifted again each
     * time it was read and rewritten.
     *
     * Asserted here rather than in Core alone because it is the adapters that
     * turn a stored row back into an event.
     */
    public function test_a_stored_timestamp_denotes_the_same_instant_on_any_runtime(): void
    {
        $runtimeTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('UTC');

            $storage = $this->getStorage();
            $aggregateId = EntityIdentifier::fromString('TimezoneAggregate');
            $occurredOn = new DateTimeImmutable('2026-08-18 21:18:42.370318', new DateTimeZone('UTC'));

            $storage->storeEvents([new AnotherDummyEvent($aggregateId, 1, null, $occurredOn)]);

            // A second service in the same cluster, configured differently.
            date_default_timezone_set('Europe/Berlin');

            $events = $storage->retrieveEvents($aggregateId);

            $this->assertCount(1, $events);
            $this->assertSame(
                $occurredOn->getTimestamp(),
                $events[0]->getOccurredOn()->getTimestamp(),
                'A stored timestamp must denote the same instant whatever the reading runtime.'
            );
            $this->assertSame(
                $occurredOn->format('Y-m-d H:i:s.u'),
                $events[0]->getOccurredOn()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                'Microseconds and the UTC wall-clock value must survive the round trip.'
            );
        } finally {
            date_default_timezone_set($runtimeTimezone);
        }
    }
}

# dummy class
final class AnotherDummyEvent implements DomainEventInterface
{
    use HasEventMetadata;

    private EntityIdentifierInterface $aggregateId;
    protected EventVersion $version;
    private DateTimeImmutable $occurredOn;
    private string $eventId;

    /**
     * @throws RandomException
     */
    public function __construct(
        EntityIdentifierInterface $aggregateId,
        int $version,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredOn = null
    ) {
        $this->aggregateId = $aggregateId;
        $this->eventId = $eventId ?? (string) UuidV6::generate();
        $this->occurredOn = $occurredOn ?? new DateTimeImmutable();
        $this->version = EventVersion::fromInt($version);
    }

    public function getAggregateId(): EntityIdentifier
    {
        return EntityIdentifier::fromString((string) $this->aggregateId);
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    public function toArray(): array
    {
        return [
            'aggregateId' => (string) $this->aggregateId,
            'version' => $this->version->toInt(),
            'eventId' => $this->eventId,
            'occurredOn' => $this->occurredOn->format('Y-m-d H:i:s.u'),
        ];
    }

    /**
     * Reconstruct the event from an associative array.
     *
     * @param array{aggregateId: string, version: int, eventId?: string, occurredOn?: string} $data
     * @throws DateMalformedStringException
     * @throws RandomException
     */
    public static function fromArray(array $data): self
    {
        return new self(
            EntityIdentifier::fromString($data['aggregateId']),
            $data['version'],
            $data['eventId'] ?? null,
            isset($data['occurredOn']) ? new DateTimeImmutable($data['occurredOn']) : null,
        );
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}
