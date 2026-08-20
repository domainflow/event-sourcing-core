<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

/**
 * Interface SnapshotHistoryStorageInterface
 *
 * A basic snapshot history storage interface for persistent storage implementations
 * that defines the methods for persisting and retrieving snapshots.
 */
interface SnapshotHistoryStorageInterface
{
    /**
     * Persist a snapshot.
     *
     * @param SnapshotInterface $snapshot
     * @return void
     */
    public function persistVersioned(SnapshotInterface $snapshot): void;

    /**
     * Retrieve a single snapshot.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return array<SnapshotInterface>
     */
    public function retrieveAll(EntityIdentifierInterface $aggregateId): array;

    /**
     * Retrieve a single snapshot.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @param int $version
     * @return void
     */
    public function deleteSingle(EntityIdentifierInterface $aggregateId, int $version): void;

    /**
     * Retrieve a single snapshot.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return void
     */
    public function deleteAll(EntityIdentifierInterface $aggregateId): void;
}
