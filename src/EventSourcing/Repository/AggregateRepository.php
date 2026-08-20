<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Repository;

use DateMalformedStringException;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventStream;
use DomainFlow\EventSourcing\Event\MetadataEnricher;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotableAggregateInterface;
use DomainFlow\EventSourcing\Interface\SnapshotFactoryInterface;
use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use InvalidArgumentException;
use ReflectionException;
use RuntimeException;

final readonly class AggregateRepository
{
    public function __construct(
        private EventStorageInterface $eventStorage,
        private ?SnapshotStorageInterface $snapshotStorage = null,
        private ?SnapshotFactoryInterface $snapshotFactory = null,
        private ?SnapshotHistoryStorageInterface $snapshotHistory = null,
        /**
         * Fills in correlation, causation, actor and tenant on the way to
         * storage. Here rather than in the facade because this is the
         * last place that sees the events before they are written, and
         * enriching after that point would store one thing and dispatch
         * another.
         */
        private ?MetadataEnricher $metadataEnricher = null,
    ) {
    }

    /**
     * Loads an aggregate of a given type by its aggregate ID.
     *
     * @template T of AggregateRoot
     * @param class-string<T> $aggregateClass
     * @param EntityIdentifierInterface $aggregateId
     * @throws ReflectionException|DateMalformedStringException
     * @return AggregateRoot
     */
    public function load(
        string $aggregateClass,
        EntityIdentifierInterface $aggregateId
    ): AggregateRoot {
        $this->ensureAggregateClass($aggregateClass);

        $snapshot = $this->snapshotStorage?->retrieveSnapshot($aggregateId);

        return $snapshot !== null
            ? $this->loadFromSnapshot($aggregateClass, $snapshot, $aggregateId)
            : $this->replayInFull($aggregateClass, $aggregateId);
    }

    /**
     * Ensures the class is an AggregateRoot.
     *
     * @param string $aggregateClass
     * @return void
     */
    private function ensureAggregateClass(
        string $aggregateClass
    ): void {
        if (!is_subclass_of($aggregateClass, AggregateRoot::class)) {
            throw new RuntimeException("Class $aggregateClass is not an AggregateRoot.");
        }
    }

    /**
     * Loads from a snapshot plus the events newer than it.
     *
     * Falls back to a full replay whenever the snapshot cannot be trusted or
     * cannot be applied. That is deliberate: a snapshot is a cache of state
     * the event stream already holds, so discarding it costs time, while
     * using a bad one silently produces an aggregate whose state never
     * existed in the domain.
     *
     * @param class-string<AggregateRoot> $aggregateClass
     * @param SnapshotInterface $snapshot
     * @param EntityIdentifierInterface $aggregateId
     * @throws ReflectionException|DateMalformedStringException
     * @return AggregateRoot
     */
    private function loadFromSnapshot(
        string $aggregateClass,
        SnapshotInterface $snapshot,
        EntityIdentifierInterface $aggregateId
    ): AggregateRoot {
        // Build through reconstitute() with an empty stream so the aggregate is
        // created by its own newInstance() factory, as the base class requires.
        $aggregate = $aggregateClass::reconstitute(new EventStream([]));

        if (!$aggregate instanceof SnapshotableAggregateInterface) {
            return $this->replayInFull($aggregateClass, $aggregateId);
        }

        if (!$this->snapshotIsPlausible($snapshot, $aggregateId)) {
            return $this->replayInFull($aggregateClass, $aggregateId);
        }

        $aggregate->applySnapshot($this->asDeclaredSnapshotClass($aggregate, $snapshot));

        // The snapshot already accounts for everything up to its own version;
        // without seeding it here an aggregate loaded from a snapshot with no
        // newer events would restart numbering at 1.
        $aggregate->restoreVersion($snapshot->getVersion());

        // Only the tail is read, and only the tail is hydrated. Reading the
        // whole stream to then discard the part the snapshot already covers
        // would make a snapshot cost more than it saves.
        foreach ($this->eventStorage->retrieveEventsFromVersion($aggregateId, $snapshot->getVersion()) as $event) {
            $aggregate->applyEvent($event, false);
        }

        return $aggregate;
    }

    /**
     * Replays the aggregate's entire stream, ignoring any snapshot.
     *
     * @param class-string<AggregateRoot> $aggregateClass
     * @param EntityIdentifierInterface $aggregateId
     * @throws ReflectionException|DateMalformedStringException
     * @return AggregateRoot
     */
    private function replayInFull(
        string $aggregateClass,
        EntityIdentifierInterface $aggregateId
    ): AggregateRoot {
        return $this->loadFromEvents($aggregateClass, $this->eventStorage->retrieveEvents($aggregateId));
    }

    /**
     * Whether a snapshot's version is consistent with the stream it belongs to.
     *
     * A snapshot claiming a version beyond the stream's own maximum is stale or
     * was written with a wrong version. Trusting it would filter out events the
     * snapshot does not actually contain — or, in the other direction, replay
     * events it already folded in, doubling counters and duplicating
     * collection entries with no error anywhere.
     *
     * A stream with no events at all is the same rule at its limit: its
     * maximum is nothing, so any snapshot claiming a version is beyond it
     *
     * Asks the store for the stream's maximum version rather than deriving it
     * from a fetched stream: the point of this path is not to fetch one.
     *
     * @param SnapshotInterface $snapshot
     * @param EntityIdentifierInterface $aggregateId
     * @return bool
     */
    private function snapshotIsPlausible(
        SnapshotInterface $snapshot,
        EntityIdentifierInterface $aggregateId
    ): bool {
        $maxVersion = $this->eventStorage->getCurrentMaxVersion($aggregateId);

        if (!$maxVersion->isAssigned()) {
            return !$snapshot->getVersion()->isAssigned();
        }

        return $snapshot->getVersion()->toInt() <= $maxVersion->toInt();
    }

    /**
     * Rebuild a stored snapshot as the class the aggregate declares.
     *
     * Storage adapters hand back a GenericSnapshot regardless of what was
     * written, so an aggregate whose applySnapshot() expects its own snapshot
     * type would otherwise never receive it. The aggregate's own
     * getSnapshotClass() is the authority here, not a column in the store.
     *
     * @param SnapshotableAggregateInterface $aggregate
     * @param SnapshotInterface $snapshot
     * @return SnapshotInterface
     */
    private function asDeclaredSnapshotClass(
        SnapshotableAggregateInterface $aggregate,
        SnapshotInterface $snapshot
    ): SnapshotInterface {
        $declaredClass = $aggregate->getSnapshotClass();

        if ($this->snapshotFactory === null || $snapshot::class === $declaredClass) {
            return $snapshot;
        }

        return $this->snapshotFactory->createFromStorage(
            $declaredClass,
            $snapshot->getAggregateId(),
            $snapshot->getVersion(),
            $snapshot->getState()
        );
    }

    /**
     * Loads by replaying all events.
     *
     * @param class-string<AggregateRoot> $aggregateClass
     * @param DomainEventInterface[] $events
     * @throws ReflectionException|DateMalformedStringException
     * @return AggregateRoot
     */
    private function loadFromEvents(
        string $aggregateClass,
        array $events
    ): AggregateRoot {
        $stream = new EventStream($this->toEntries($events));

        return $aggregateClass::reconstitute($stream);
    }

    /**
     * Wraps the events the storage reconstructed in the entries
     *
     * `fromDomainEvent()` used to stand here, and it does the opposite of what
     * this path needs: it serialises the event so the entry can hydrate it
     * again. The storage has already hydrated it — through the factory and the
     * upcaster it was configured with, and through the payload migration — so
     * rebuilding from `toArray()` re-ran that work with none of those rules
     * available. What came back was a reflection-built copy: a factory-only
     * event rebuilt without its factory, a payload migrated a second time, and
     * anything `toArray()` does not expose missing outright.
     *
     * @param DomainEventInterface[] $events
     * @return EventEntry[]
     */
    private function toEntries(
        array $events
    ): array {
        return array_map(
            fn (DomainEventInterface $e) => EventEntry::fromReconstructedEvent($e),
            $events
        );
    }

    /**
     * Stores uncommitted events.
     *
     * Returns what was actually stored, which is not always what the aggregate
     * emitted: metadata is attached here, and an event carrying it is a
     * different object from the one that did not. A caller that dispatches
     * afterwards has to hand on these, or subscribers see events the store
     * does not have.
     *
     * @param AggregateRoot $aggregate
     * @return array<DomainEventInterface>
     */
    public function save(
        AggregateRoot $aggregate
    ): array {
        $events = $aggregate->getUncommittedEvents();
        if (empty($events)) {
            return [];
        }

        $events = $this->metadataEnricher?->enrich($events) ?? $events;

        $this->eventStorage->storeEvents($events);
        $aggregate->clearUncommittedEvents();

        return $events;
    }

    /**
     * Stores events and snapshot (if provided).
     *
     * @param AggregateRoot $aggregate
     * @param SnapshotInterface|null $snapshot
     * @return array<DomainEventInterface> The events as stored — see save().
     */
    public function saveWithSnapshot(
        AggregateRoot $aggregate,
        ?SnapshotInterface $snapshot = null
    ): array {
        $stored = $this->save($aggregate);

        if ($snapshot !== null) {
            $this->persistSnapshot($snapshot);

            return $stored;
        }

        if ($this->shouldGenerateSnapshot($aggregate)) {
            $generated = $this->generateSnapshotFromAggregate($aggregate);
            if ($generated !== null) {
                $this->persistSnapshot($generated);
            }
        }

        return $stored;
    }

    /**
     * Determines if a snapshot should be generated for the aggregate.
     *
     * @param AggregateRoot $aggregate
     * @return bool
     */
    private function shouldGenerateSnapshot(
        AggregateRoot $aggregate
    ): bool {
        return $aggregate instanceof SnapshotableAggregateInterface
            && $aggregate->shouldTakeSnapshot();
    }

    /**
     * Generates a snapshot from the aggregate if it is snapshotable.
     *
     * @param AggregateRoot $aggregate
     * @return SnapshotInterface|null
     */
    private function generateSnapshotFromAggregate(
        AggregateRoot $aggregate
    ): ?SnapshotInterface {
        if (!$aggregate instanceof SnapshotableAggregateInterface) {
            return null;
        }

        return $this->buildSnapshot($aggregate, $aggregate->getSnapshotClass());
    }

    /**
     * @param class-string $snapshotClass
     * @return SnapshotInterface|null
     */
    private function buildSnapshot(
        SnapshotableAggregateInterface $aggregate,
        string $snapshotClass
    ): ?SnapshotInterface {
        if (!is_a($snapshotClass, SnapshotInterface::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Snapshot class "%s" must implement SnapshotInterface.',
                $snapshotClass
            ));
        }

        /** @var class-string<SnapshotInterface> $snapshotClass */
        return $this->snapshotFactory?->createFromStorage(
            $snapshotClass,
            $aggregate->getAggregateId(),
            $aggregate->getSnapshotVersion(),
            $aggregate->getSnapshotState()
        );
    }

    /**
     * Persists a snapshot to storage.
     *
     * @param SnapshotInterface $snapshot
     * @return void
     */
    private function persistSnapshot(
        SnapshotInterface $snapshot
    ): void {
        $this->snapshotStorage?->storeSnapshot($snapshot);
        $this->snapshotHistory?->persistVersioned($snapshot);
    }

    /**
     * Deletes all events and snapshot data for an aggregate.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return void
     */
    public function delete(
        EntityIdentifierInterface $aggregateId
    ): void {
        $this->eventStorage->deleteEvents($aggregateId);
        $this->snapshotStorage?->deleteSnapshot($aggregateId);
        $this->snapshotHistory?->deleteAll($aggregateId);
    }

    /**
     * Create a snapshot from the aggregate's state using the snapshot factory.
     *
     * @param AggregateRoot $aggregate
     * @param class-string $snapshotClass
     * @return SnapshotInterface|null
     */
    public function createSnapshot(
        AggregateRoot $aggregate,
        string $snapshotClass
    ): ?SnapshotInterface {
        if ($this->snapshotFactory === null || !$aggregate instanceof SnapshotableAggregateInterface) {
            return null;
        }

        return $this->buildSnapshot($aggregate, $snapshotClass);
    }
}
