<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Trait;

use DomainFlow\EventSourcing\Aggregate\AggregateId;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventMetadata;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversTrait(HasEventMetadata::class)]
#[UsesClass(EventMetadata::class)]
#[UsesClass(SourceEvent::class)]
#[UsesClass(AggregateId::class)]
#[UsesClass(EventId::class)]
#[UsesClass(EventVersion::class)]
#[UsesClass(OccurredOn::class)]
final class HasEventMetadataTest extends TestCase
{
    public function test_anEventStartsWithEmptyMetadataRatherThanNone(): void
    {
        $this->assertTrue($this->event()->getMetadata()->isEmpty());
    }

    /**
     * A copy, not a mutation. An event already handed to a caller — or already
     * written — must not change underneath them.
     */
    public function test_withMetadataReturnsACopy(): void
    {
        $original = $this->event();
        $tagged = $original->withMetadata(EventMetadata::empty()->withCorrelationId('corr-1'));

        $this->assertNotSame($original, $tagged);
        $this->assertTrue($original->getMetadata()->isEmpty());
        $this->assertSame('corr-1', $tagged->getMetadata()->getCorrelationId());
    }

    public function test_theCopyKeepsEverythingElseAboutTheEvent(): void
    {
        $original = $this->event();
        $tagged = $original->withMetadata(EventMetadata::empty()->withTenantId('tenant-a'));

        $this->assertSame((string) $original->getAggregateId(), (string) $tagged->getAggregateId());
        $this->assertSame($original->getVersion()->toInt(), $tagged->getVersion()->toInt());
    }

    private function event(): TraitDummyEvent
    {
        return new TraitDummyEvent(AggregateId::generate(), EventId::generate());
    }
}

final class TraitDummyEvent extends SourceEvent
{
}
