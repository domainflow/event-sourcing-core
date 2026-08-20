<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Trait;

use ArrayIterator;
use DomainFlow\EventSourcing\Event\EventStream;
use DomainFlow\EventSourcing\Interface\SnapshotableAggregateInterface;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshot;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing()]
final class DummySnapshotableAggregateTest extends TestCase
{
    public function test_implementsSnapshotableAggregateInterface(): void
    {
        $aggregate = new DummySnapshotableAggregate();
        $this->assertInstanceOf(SnapshotableAggregateInterface::class, $aggregate);
    }

    public function test_shouldTakeSnapshotReturnsTrue(): void
    {
        $aggregate = new DummySnapshotableAggregate();
        $this->assertTrue($aggregate->shouldTakeSnapshot());
    }

    public function test_setSnapshotClassReturnsGenericSnapshot(): void
    {
        $aggregate = new DummySnapshotableAggregate();
        $this->assertSame(GenericSnapshot::class, $aggregate->getSnapshotClass());
    }

    public function test_getSnapshotStateReturnsArray(): void
    {
        $aggregate = new DummySnapshotableAggregate();
        $state = $aggregate->getSnapshotState();

        $this->assertArrayHasKey('something', $state);
        $this->assertSame('useful', $state['something']);
    }

    public function test_getSnapshotVersionOfAnAggregateWithoutHistoryIsUnassigned(): void
    {
        $aggregate = new DummySnapshotableAggregate();
        $this->assertSame(0, $aggregate->getSnapshotVersion()->toInt());
        $this->assertFalse($aggregate->getSnapshotVersion()->isAssigned());
    }

    public function test_reconstituteCreatesNewInstanceViaFactory(): void
    {
        $stubStream = $this->createStub(EventStream::class);
        $stubStream->method('getIterator')->willReturn(new ArrayIterator([]));

        $aggregate = DummySnapshotableAggregate::reconstitute($stubStream);

        $this->assertInstanceOf(DummySnapshotableAggregate::class, $aggregate);
    }
}
