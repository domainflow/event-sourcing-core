<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Setup;

use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use DomainFlow\EventSourcing\Snapshot\InMemorySnapshotHistoryStorage;
use DomainFlow\EventSourcing\Snapshot\InMemorySnapshotStorage;
use DomainFlow\EventSourcing\Storage\InMemoryEventStorage;

trait InMemorySetup
{
    protected InMemoryEventStorage $eventStorage;
    protected InMemorySnapshotStorage $snapshotStorage;
    protected InMemorySnapshotHistoryStorage $snapshotHistoryStorage;

    public function setUp(): void
    {
        $this->eventStorage = new InMemoryEventStorage();
        $this->snapshotStorage = new InMemorySnapshotStorage();
        $this->snapshotHistoryStorage = new InMemorySnapshotHistoryStorage();

        parent::setUp();
    }

    protected function getStorage(): EventStorageInterface
    {
        return $this->eventStorage;
    }

    protected function getSnapshotStorage(): SnapshotStorageInterface
    {
        return $this->snapshotStorage;
    }

    protected function getSnapshotHistoryStorage(): SnapshotHistoryStorageInterface
    {
        return $this->snapshotHistoryStorage;
    }
}
