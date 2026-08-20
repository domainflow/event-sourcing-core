<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\GlobalEventPage;
use DomainFlow\EventSourcing\Exception\ConcurrencyException;

interface EventStorageInterface
{
    /**
     * Append a batch of events, all or nothing.
     *
     * The unit is the call, not the aggregate. A batch may carry events for
     * any number of aggregates, and either every one of them is appended or
     * none is. After a throw the store is exactly as it was before the call:
     * no event from the batch is readable, no aggregate's current version has
     * moved, and no global position that a reader could observe was consumed.
     * A caller that catches the throw may retry the identical batch, and may
     * do so as many times as it likes.
     *
     * This is stated here because it used to be answered per adapter.
     * The one deployment that cannot meet it is MongoDB on a standalone
     * server, which has no transactions at all; that adapter refuses to write
     * unless the operator explicitly accepts the weaker guarantee, so a
     * consumer who was not asked may rely on the paragraph above.
     *
     * Nothing here promises anything about a *concurrent* reader mid-call: the
     * guarantee is about what remains after the call returns or throws.
     *
     * @param array<DomainEventInterface> $events
     * @throws ConcurrencyException When a version in the batch is already
     *         taken, or the batch is internally inconsistent.
     * @return void
     */
    public function storeEvents(array $events): void;

    /**
     * Retrieve events for a specific aggregate.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return array<DomainEventInterface>
     */
    public function retrieveEvents(EntityIdentifierInterface $aggregateId): array;

    /**
     * Retrieve an aggregate's events newer than a given version, in version
     * order. Exclusive: an event whose version equals $afterVersion is not
     * returned.
     *
     * The bound belongs in the query, not in a filter after the fact. The
     * snapshot load path is the reason this exists: without it, an aggregate
     * with 50,000 events and a snapshot at 49,990 still hydrates all 50,000
     * through reflection to then discard all but ten, which makes snapshots
     * worthless as a load-time optimisation.
     *
     * An unassigned version means "everything", since no stored event can
     * precede the first one.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @param EventVersion $afterVersion
     * @return array<DomainEventInterface>
     */
    public function retrieveEventsFromVersion(
        EntityIdentifierInterface $aggregateId,
        EventVersion $afterVersion
    ): array;

    /**
     * Retrieve every event in the store, in global order, lazily.
     *
     * Returns an iterable rather than an array because an event store is
     * unbounded by design: materialising it would run the process out of memory
     * long before anything else went wrong, and the caller who asked for "all
     * events" is rarely the caller who can afford them all at once.
     *
     * A reader that needs to stop, resume, or survive a restart wants
     * retrieveEventsFromPosition() instead. This one is for a full sweep in one
     * pass — a rebuild, an export, a test.
     *
     * @return iterable<DomainEventInterface>
     */
    public function retrieveAllEvents(): iterable;

    /**
     * Delete all events for a specific aggregate.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return void
     */
    public function deleteEvents(EntityIdentifierInterface $aggregateId): void;

    /**
     * Read the global event stream from a position, for a projector or any
     * other cross-aggregate reader that has to be able to catch up.
     *
     * Pass null to start at the beginning of the stream, then pass back the
     * page's own nextPosition. The position is an opaque, adapter-defined
     * string: persist it, hand it back, never parse it.
     *
     * The guarantee is that a reader which only ever resumes from a position
     * it received sees every event exactly once, no matter how many writes
     * land between two reads. That is what retrievePaginatedEvents() cannot
     * give: an offset counts from the start of a stream that is still growing,
     * so a write landing mid-scan shifts every later event by one and the
     * reader skips or repeats accordingly.
     *
     * One limit, stated because it is invisible when it bites: a
     * position is assigned when a write starts, and the event becomes readable
     * when the write completes. Where those are two different moments, a
     * concurrent writer can still be in flight with a lower position than one a
     * reader has already passed, and that event is then missed. This holds for
     * any adapter whose position comes from a sequence taken before the write
     * lands — MySQL's AUTO_INCREMENT and MongoDB's counter both do. Redis does
     * not: its position and its visibility happen inside the same atomic Lua
     * script. Treat the exactly-once wording above as holding for a single
     * writer, and as best-effort under concurrent
     * ones. `Projector\CatchUpReader` exists to bound that: it re-reads from a
     * lagging safe position rather than trusting the frontier, so a late
     * commit is still picked up. Use it rather than driving this method
     * directly if the store has more than one writer.
     *
     * @param string|null $afterPosition
     * @param int $limit Maximum number of events in the page.
     * @return GlobalEventPage
     */
    public function retrieveEventsFromPosition(?string $afterPosition, int $limit): GlobalEventPage;

    /**
     * Retrieve a paginated list of events.
     *
     * @deprecated Offset pagination over a growing stream skips and repeats
     *             events whenever a write lands mid-scan, and there is no
     *             cursor to resume from after a restart. Use
     *             retrieveEventsFromPosition() for anything that reads the
     *             global stream more than once. Kept for compatibility.
     * @param int|null $offset
     * @param int|null $limit
     * @return array<DomainEventInterface>
     */
    public function retrievePaginatedEvents(?int $offset, ?int $limit): array;

    /**
     * Retrieve the current max version for an aggregate.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return EventVersion
     */
    public function getCurrentMaxVersion(EntityIdentifierInterface $aggregateId): EventVersion;
}
