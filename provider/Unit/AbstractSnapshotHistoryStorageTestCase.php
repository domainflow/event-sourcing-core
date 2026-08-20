<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Unit;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshot;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class AbstractSnapshotHistoryStorageTestCase extends TestCase
{
    protected function getSnapshotHistoryStorage(): ?SnapshotHistoryStorageInterface
    {
        return null;
    }

    public function test_versioned_snapshot_storage_if_supported(): void
    {
        $history = $this->getSnapshotHistoryStorage();
        if (!$history) {
            $this->markTestSkipped('SnapshotHistoryStorageInterface not supported by this adapter.');
        }

        $aggregateId = EntityIdentifier::fromString('agg-001');

        $snapshot1 = new GenericSnapshot(
            $aggregateId,
            EventVersion::fromInt(1),
            [
                'val' => 'v1',
            ],
            OccurredOn::fromString('-3 days')
        );
        $snapshot2 = new GenericSnapshot(
            $aggregateId,
            EventVersion::fromInt(2),
            [
                'val' => 'v2',
            ],
            OccurredOn::fromString('-2 days')
        );
        $snapshot3 = new GenericSnapshot(
            $aggregateId,
            EventVersion::fromInt(3),
            [
                'val' => 'v3',
            ],
            OccurredOn::fromString('-1 day')
        );

        $history->persistVersioned($snapshot1);
        $history->persistVersioned($snapshot2);
        $history->persistVersioned($snapshot3);

        $all = $history->retrieveAll($aggregateId);

        $this->assertCount(3, $all);
        $this->assertSame(1, $all[0]->getVersion()->toInt());
        $this->assertSame(3, $all[2]->getVersion()->toInt());

        $history->deleteSingle($aggregateId, 2);
        $remaining = $history->retrieveAll($aggregateId);

        $this->assertCount(2, $remaining);
        $this->assertSame([1, 3], array_map(fn ($s) => $s->getVersion()->toInt(), $remaining));

        $history->deleteAll($aggregateId);

        $this->assertSame([], $history->retrieveAll($aggregateId));
    }

    public function test_retrieveAll_throws_if_snapshot_state_is_corrupt(): void
    {
        $history = $this->getSnapshotHistoryStorage();

        if ($history === null) {
            $this->markTestSkipped('SnapshotHistoryStorageInterface not supported by this adapter.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to decode snapshot history state for aggregate');

        $history->retrieveAll(EntityIdentifier::fromString('corrupt-agg'));
    }

    public function test_retrieveAll_falls_back_to_now_when_occurred_on_is_invalid_string(): void
    {
        $history = $this->getSnapshotHistoryStorage();

        if ($history === null) {
            $this->markTestSkipped('SnapshotHistoryStorageInterface not supported by this adapter.');
        }

        $snapshots = $history->retrieveAll(EntityIdentifier::fromString('invalid-date-agg'));

        $this->assertCount(1, $snapshots);
        $this->assertInstanceOf(DateTimeImmutable::class, $snapshots[0]->getOccurredOn());
    }
}
