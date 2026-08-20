<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Snapshot;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Snapshot\InMemorySnapshotHistoryStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityIdentifier::class)]
#[CoversClass(EventVersion::class)]
#[CoversClass(InMemorySnapshotHistoryStorage::class)]
final class InMemorySnapshotHistoryStorageTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function test_persist_and_retrieve_snapshots(): void
    {
        $storage = new InMemorySnapshotHistoryStorage();

        $snapshot1 = $this->createSnapshot('agg-1', 1);
        $snapshot2 = $this->createSnapshot('agg-1', 2);

        $storage->persistVersioned($snapshot2);
        $storage->persistVersioned($snapshot1);

        $retrieved = $storage->retrieveAll(EntityIdentifier::fromString('agg-1'));

        $this->assertSame([$snapshot1, $snapshot2], $retrieved);
    }

    /**
     * @throws Exception
     */
    public function test_delete_single_snapshot(): void
    {
        $storage = new InMemorySnapshotHistoryStorage();
        $snapshot = $this->createSnapshot('agg-2', 1);

        $storage->persistVersioned($snapshot);
        $storage->deleteSingle(EntityIdentifier::fromString('agg-2'), 1);

        $retrieved = $storage->retrieveAll(EntityIdentifier::fromString('agg-2'));
        $this->assertSame([], $retrieved);
    }

    /**
     * @throws Exception
     */
    public function test_delete_all_snapshots(): void
    {
        $storage = new InMemorySnapshotHistoryStorage();
        $snapshot1 = $this->createSnapshot('agg-3', 1);
        $snapshot2 = $this->createSnapshot('agg-3', 2);

        $storage->persistVersioned($snapshot1);
        $storage->persistVersioned($snapshot2);
        $storage->deleteAll(EntityIdentifier::fromString('agg-3'));

        $retrieved = $storage->retrieveAll(EntityIdentifier::fromString('agg-3'));
        $this->assertSame([], $retrieved);
    }

    public function test_retrieve_returns_empty_array_when_none_exist(): void
    {
        $storage = new InMemorySnapshotHistoryStorage();

        $retrieved = $storage->retrieveAll(EntityIdentifier::fromString('missing-agg'));
        $this->assertSame([], $retrieved);
    }

    /**
     * @throws Exception
     */
    private function createSnapshot(
        string $aggregateId,
        int $version
    ): SnapshotInterface {
        $mock = $this->createStub(SnapshotInterface::class);
        $mock->method('getAggregateId')->willReturn(EntityIdentifier::fromString($aggregateId));
        $mock->method('getVersion')->willReturn(EventVersion::fromInt($version));

        return $mock;
    }
}
