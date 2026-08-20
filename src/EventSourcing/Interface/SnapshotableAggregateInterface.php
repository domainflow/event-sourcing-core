<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

use DomainFlow\EventSourcing\Event\EventVersion;

interface SnapshotableAggregateInterface
{
    /**
     * Whether this aggregate should be snapshotted now.
     *
     * @return bool
     */
    public function shouldTakeSnapshot(): bool;

    /**
     * The snapshot class to use when serializing this aggregate.
     *
     * @return class-string<SnapshotInterface>
     */
    public function getSnapshotClass(): string;

    /**
     * Returns the snapshot payload (state) for this aggregate.
     *
     * @return array<string, mixed>
     */
    public function getSnapshotState(): array;

    /**
     * Returns the current version of the aggregate.
     *
     * @return EventVersion
     */
    public function getSnapshotVersion(): EventVersion;

    /**
     * The identifier of the aggregate this snapshot belongs to.
     *
     * A snapshot without an aggregate id cannot be stored or found again, so
     * an aggregate that can be snapshotted has to be able to name itself.
     *
     * @return EntityIdentifierInterface
     */
    public function getAggregateId(): EntityIdentifierInterface;

    /**
     * Restore this aggregate's state from a snapshot.
     *
     * The counterpart of getSnapshotState(). AggregateRepository calls this
     * when loading from a snapshot, then replays only the events newer than
     * the snapshot's version on top.
     *
     * @param SnapshotInterface $snapshot
     * @return void
     */
    public function applySnapshot(SnapshotInterface $snapshot): void;
}
