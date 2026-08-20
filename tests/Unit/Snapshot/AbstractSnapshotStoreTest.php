<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Snapshot;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use DomainFlow\EventSourcing\Snapshot\AbstractSnapshotStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityIdentifier::class)]
#[CoversClass(AbstractSnapshotStore::class)]
final class AbstractSnapshotStoreTest extends TestCase
{
    private SnapshotStorageInterface $storage;
    private AbstractSnapshotStore $snapshotStore;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        $this->storage = $this->createMock(SnapshotStorageInterface::class);
        $this->snapshotStore = new class($this->storage) extends AbstractSnapshotStore {
            public function __construct(
                SnapshotStorageInterface $storage
            ) {
                parent::__construct($storage);
            }
        };
    }

    /**
     * @throws Exception
     */
    public function test_savesSnapshotSuccessfully(): void
    {
        $snapshot = $this->createStub(SnapshotInterface::class);
        $this->storage->expects($this->once())
            ->method('storeSnapshot')
            ->with($snapshot);

        $this->snapshotStore->saveSnapshot($snapshot);
    }

    /**
     * @throws Exception
     */
    public function test_retrievesSnapshotSuccessfully(): void
    {
        $aggregateId = EntityIdentifier::fromString('aggregate-id');
        $snapshot = $this->createStub(SnapshotInterface::class);
        $this->storage->expects($this->once())
            ->method('retrieveSnapshot')
            ->with($aggregateId)
            ->willReturn($snapshot);

        $result = $this->snapshotStore->getSnapshot($aggregateId);
        $this->assertSame($snapshot, $result);
    }

    public function test_returnsNullWhenSnapshotNotFound(): void
    {
        $aggregateId = EntityIdentifier::fromString('non-existent-id');
        $this->storage->expects($this->once())
            ->method('retrieveSnapshot')
            ->with($aggregateId)
            ->willReturn(null);

        $result = $this->snapshotStore->getSnapshot($aggregateId);
        $this->assertNull($result);
    }
}
