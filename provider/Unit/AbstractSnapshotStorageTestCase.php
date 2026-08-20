<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Unit;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshot;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class AbstractSnapshotStorageTestCase extends TestCase
{
    abstract protected function getSnapshotStorage(): SnapshotStorageInterface;

    public function test_store_and_retrieve_snapshot(): void
    {
        $storage = $this->getSnapshotStorage();

        $snapshot = new GenericSnapshot(
            EntityIdentifier::fromString('test-123'),
            EventVersion::fromInt(1),
            ['foo' => 'bar'],
            OccurredOn::fromString('2024-01-01 12:00:00')
        );

        $storage->storeSnapshot($snapshot);

        $retrieved = $storage->retrieveSnapshot(EntityIdentifier::fromString('test-123'));

        $this->assertInstanceOf(SnapshotInterface::class, $retrieved);
        $this->assertSame((string) $snapshot->getAggregateId(), (string) $retrieved->getAggregateId());
        $this->assertSame($snapshot->getVersion()->toInt(), $retrieved->getVersion()->toInt());
        $this->assertEquals($snapshot->getState(), $retrieved->getState());
        $this->assertEquals(
            $snapshot->getOccurredOn()->format('Y-m-d H:i:s'),
            $retrieved->getOccurredOn()->format('Y-m-d H:i:s')
        );
    }

    public function test_deleteSnapshot_removes_the_stored_snapshot(): void
    {
        $storage = $this->getSnapshotStorage();
        $aggregateId = EntityIdentifier::fromString('delete-me');

        $storage->storeSnapshot(new GenericSnapshot(
            $aggregateId,
            EventVersion::fromInt(3),
            ['foo' => 'bar'],
            OccurredOn::fromString('2024-01-01 12:00:00')
        ));

        $this->assertNotNull($storage->retrieveSnapshot($aggregateId), 'Precondition: the snapshot exists.');

        $storage->deleteSnapshot($aggregateId);

        $this->assertNull(
            $storage->retrieveSnapshot($aggregateId),
            'A deleted aggregate must not resurrect from a leftover snapshot.'
        );
    }

    public function test_deleteSnapshot_leaves_other_aggregates_alone(): void
    {
        $storage = $this->getSnapshotStorage();
        $doomed = EntityIdentifier::fromString('delete-one');
        $survivor = EntityIdentifier::fromString('keep-one');

        foreach ([$doomed, $survivor] as $aggregateId) {
            $storage->storeSnapshot(new GenericSnapshot(
                $aggregateId,
                EventVersion::fromInt(1),
                ['id' => (string) $aggregateId],
                OccurredOn::fromString('2024-01-01 12:00:00')
            ));
        }

        $storage->deleteSnapshot($doomed);

        $this->assertNull($storage->retrieveSnapshot($doomed));
        $this->assertNotNull($storage->retrieveSnapshot($survivor));
    }

    public function test_deleteSnapshot_is_a_no_op_for_an_unknown_aggregate(): void
    {
        $storage = $this->getSnapshotStorage();

        $storage->deleteSnapshot(EntityIdentifier::fromString('never-stored'));

        $this->assertNull($storage->retrieveSnapshot(EntityIdentifier::fromString('never-stored')));
    }

    public function test_retrieveSnapshot_returns_null_when_snapshot_does_not_exist(): void
    {
        $snapshot = $this->getSnapshotStorage()->retrieveSnapshot(EntityIdentifier::fromString('non-existent-id'));
        $this->assertNull($snapshot);
    }

    public function test_retrieveSnapshot_throws_on_invalid_json(): void
    {
        $storage = $this->getSnapshotStorage();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to decode snapshot state for aggregate');

        $storage->retrieveSnapshot(EntityIdentifier::fromString('json-corrupt-id'));
    }

    public function test_retrieveSnapshot_falls_back_to_now_when_occurred_on_invalid(): void
    {
        $storage = $this->getSnapshotStorage();

        $snapshot = $storage->retrieveSnapshot(EntityIdentifier::fromString('bad-occurred-id'));

        $this->assertInstanceOf(SnapshotInterface::class, $snapshot);
        $this->assertInstanceOf(DateTimeImmutable::class, $snapshot->getOccurredOn());
    }
}
