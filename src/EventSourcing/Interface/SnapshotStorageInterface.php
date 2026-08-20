<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

interface SnapshotStorageInterface
{
    /**
     * Store a snapshot.
     *
     * @param SnapshotInterface $snapshot
     * @return void
     */
    public function storeSnapshot(SnapshotInterface $snapshot): void;

    /**
     * Retrieve a snapshot.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return SnapshotInterface|null
     */
    public function retrieveSnapshot(EntityIdentifierInterface $aggregateId): ?SnapshotInterface;

    /**
     * Delete the stored snapshot for an aggregate.
     *
     * A snapshot holds the aggregate's complete state, so deleting an
     * aggregate has to remove it too — otherwise the next load resurrects the
     * deleted aggregate from the leftover snapshot.
     *
     * Deleting a snapshot that does not exist is not an error.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return void
     */
    public function deleteSnapshot(EntityIdentifierInterface $aggregateId): void;
}
