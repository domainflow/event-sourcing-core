<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Snapshot;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Snapshot\InMemorySnapshotStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityIdentifier::class)]
#[CoversClass(InMemorySnapshotStorage::class)]
final class InMemorySnapshotStorageTest extends TestCase
{
    public function test_deleteSnapshotRemovesTheStoredSnapshot(): void
    {
        $storage = new InMemorySnapshotStorage();
        $aggregateId = EntityIdentifier::fromString('agg-delete');

        $snapshot = $this->createStub(SnapshotInterface::class);
        $snapshot->method('getAggregateId')->willReturn($aggregateId);

        $storage->storeSnapshot($snapshot);
        $this->assertNotNull($storage->retrieveSnapshot($aggregateId));

        $storage->deleteSnapshot($aggregateId);

        $this->assertNull(
            $storage->retrieveSnapshot($aggregateId),
            'A deleted aggregate must not resurrect from a leftover snapshot.'
        );
    }

    public function test_deleteSnapshotLeavesOtherAggregatesAlone(): void
    {
        $storage = new InMemorySnapshotStorage();
        $doomed = EntityIdentifier::fromString('agg-doomed');
        $survivor = EntityIdentifier::fromString('agg-survivor');

        foreach ([$doomed, $survivor] as $aggregateId) {
            $snapshot = $this->createStub(SnapshotInterface::class);
            $snapshot->method('getAggregateId')->willReturn($aggregateId);
            $storage->storeSnapshot($snapshot);
        }

        $storage->deleteSnapshot($doomed);

        $this->assertNull($storage->retrieveSnapshot($doomed));
        $this->assertNotNull($storage->retrieveSnapshot($survivor));
    }

    public function test_deleteSnapshotIsANoOpForAnUnknownAggregate(): void
    {
        $storage = new InMemorySnapshotStorage();

        $storage->deleteSnapshot(EntityIdentifier::fromString('never-stored'));

        $this->assertNull($storage->retrieveSnapshot(EntityIdentifier::fromString('never-stored')));
    }

    /**
     * @throws Exception
     */
    public function test_store_and_retrieve_snapshot(): void
    {
        $snapshot = $this->createStub(SnapshotInterface::class);
        $snapshot->method('getAggregateId')->willReturn(EntityIdentifier::fromString('abc'));

        $storage = new InMemorySnapshotStorage();
        $storage->storeSnapshot($snapshot);

        $retrieved = $storage->retrieveSnapshot(EntityIdentifier::fromString('abc'));
        $this->assertSame($snapshot, $retrieved);
    }

    public function test_returns_null_if_snapshot_not_found(): void
    {
        $storage = new InMemorySnapshotStorage();
        $this->assertNull($storage->retrieveSnapshot(EntityIdentifier::fromString('missing-id')));
    }

    /**
     * @throws Exception
     */
    public function test_overwrites_existing_snapshot(): void
    {
        $first = $this->createStub(SnapshotInterface::class);
        $first->method('getAggregateId')->willReturn(EntityIdentifier::fromString('overwrite-id'));

        $second = $this->createStub(SnapshotInterface::class);
        $second->method('getAggregateId')->willReturn(EntityIdentifier::fromString('overwrite-id'));

        $storage = new InMemorySnapshotStorage();
        $storage->storeSnapshot($first);
        $storage->storeSnapshot($second);

        $retrieved = $storage->retrieveSnapshot(EntityIdentifier::fromString('overwrite-id'));
        $this->assertSame($second, $retrieved);
    }

}
