<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Snapshot;

use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;

final class InMemorySnapshotHistoryStorage implements SnapshotHistoryStorageInterface
{
    /** @var array<string, array<int, SnapshotInterface>> */
    private array $history = [];

    /**
     * Persists a versioned snapshot.
     *
     * @param SnapshotInterface $snapshot
     * @return void
     */
    public function persistVersioned(
        SnapshotInterface $snapshot
    ): void {
        $this->history[
            (string) $snapshot->getAggregateId()][$snapshot->getVersion()->toInt()] = $snapshot;
        ksort($this->history[(string) $snapshot->getAggregateId()]);
    }

    /**
     * Persists a snapshot.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @param int $version
     * @return void
     */
    public function deleteSingle(
        EntityIdentifierInterface $aggregateId,
        int $version
    ): void {
        unset($this->history[(string) $aggregateId][$version]);
    }

    /**
     * Deletes all snapshots for an aggregate.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return void
     */
    public function deleteAll(
        EntityIdentifierInterface $aggregateId
    ): void {
        unset($this->history[(string) $aggregateId]);
    }

    /**
     * Retrieves a single snapshot.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return array<int, SnapshotInterface>
     */
    public function retrieveAll(
        EntityIdentifierInterface $aggregateId
    ): array {
        return array_values($this->history[(string) $aggregateId] ?? []);
    }
}
