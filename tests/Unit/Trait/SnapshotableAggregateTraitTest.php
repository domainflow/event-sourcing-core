<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Trait;

use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshot;
use DomainFlow\EventSourcing\Trait\SnapshotableAggregateTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventVersion::class)]
#[CoversTrait(SnapshotableAggregateTrait::class)]
final class SnapshotableAggregateTraitTest extends TestCase
{
    public function test_returnsGenericSnapshotClass(): void
    {
        $aggregate = new DummySnapshotableAggregate();

        $this->assertSame(GenericSnapshot::class, $aggregate->getSnapshotClass());
    }

    public function test_ReturnsVersionPropertyIfExists(): void
    {
        $aggregate = new class() extends DummySnapshotableAggregate {
            public EventVersion $version;

            public function __construct()
            {
                parent::__construct();
                $this->version = EventVersion::fromInt(42);
            }
        };

        $this->assertSame(42, $aggregate->getSnapshotVersion()->toInt());
    }

    public function test_returnsUnassignedVersionIfVersionMissing(): void
    {
        $aggregate = new class() {
            use SnapshotableAggregateTrait;
        };

        $this->assertSame(0, $aggregate->getSnapshotVersion()->toInt());
        $this->assertFalse($aggregate->getSnapshotVersion()->isAssigned());
    }
}
