<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Snapshot;

use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;

final class InMemorySnapshotStorage implements SnapshotStorageInterface
{
    /** @var array<string, SnapshotInterface> */
    private array $snapshots = [];

    /**
     * Stores a snapshot in memory.
     *
     * @param SnapshotInterface $snapshot
     * @return void
     */
    public function storeSnapshot(
        SnapshotInterface $snapshot
    ): void {
        $this->snapshots[(string) $snapshot->getAggregateId()] = $snapshot;
    }

    /**
     * Deletes the stored snapshot for an aggregate.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return void
     */
    public function deleteSnapshot(
        EntityIdentifierInterface $aggregateId
    ): void {
        unset($this->snapshots[(string) $aggregateId]);
    }

    /**
     * Retrieves a snapshot from memory.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return SnapshotInterface|null
     */
    public function retrieveSnapshot(
        EntityIdentifierInterface $aggregateId
    ): ?SnapshotInterface {
        return $this->snapshots[(string) $aggregateId] ?? null;
    }
}
