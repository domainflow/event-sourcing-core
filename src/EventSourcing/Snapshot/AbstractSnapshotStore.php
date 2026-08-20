<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Snapshot;

use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;

abstract class AbstractSnapshotStore
{
    protected SnapshotStorageInterface $storage;

    public function __construct(
        SnapshotStorageInterface $storage
    ) {
        $this->storage = $storage;
    }

    /**
     * Save a snapshot to the store.
     *
     * @param SnapshotInterface $snapshot
     * @return void
     */
    public function saveSnapshot(
        SnapshotInterface $snapshot
    ): void {
        $this->storage->storeSnapshot($snapshot);
    }

    /**
     * Retrieve a snapshot from the store.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return SnapshotInterface|null
     */
    public function getSnapshot(
        EntityIdentifierInterface $aggregateId
    ): ?SnapshotInterface {
        return $this->storage->retrieveSnapshot($aggregateId);
    }
}
