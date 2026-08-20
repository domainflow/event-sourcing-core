<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Trait;

use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshot;

/**
 * Trait for aggregates that support snapshotting.
 *
 * Provides default implementations for getSnapshotClass() and getSnapshotVersion().
 * Requires implementing getSnapshotState() and shouldTakeSnapshot().
 */
trait SnapshotableAggregateTrait
{
    /**
     * Returns the default snapshot class.
     *
     * @return class-string<SnapshotInterface>
     */
    public function getSnapshotClass(): string
    {
        return GenericSnapshot::class;
    }

    /**
     * Returns the aggregate version for snapshotting.
     *
     * Falls back to the unassigned sentinel, not to 1: an aggregate with no
     * history has no version, and claiming version 1 would make
     * AggregateRepository::loadFromSnapshot() discard the first real event of
     * the stream when reading that snapshot back.
     *
     * @return EventVersion
     */
    public function getSnapshotVersion(): EventVersion
    {
        /** @phpstan-ignore-next-line  */
        return isset($this->version) ? $this->version : EventVersion::unassigned();
    }
}
