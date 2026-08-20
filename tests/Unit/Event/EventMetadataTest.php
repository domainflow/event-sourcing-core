<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Event;

use DomainFlow\EventSourcing\Event\EventMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventMetadata::class)]
final class EventMetadataTest extends TestCase
{
    public function test_anEventWithoutMetadataIsLegal(): void
    {
        $metadata = EventMetadata::empty();

        $this->assertNull($metadata->getCorrelationId());
        $this->assertNull($metadata->getCausationId());
        $this->assertNull($metadata->getActorId());
        $this->assertNull($metadata->getTenantId());
        $this->assertSame([], $metadata->getCustom());
        $this->assertTrue($metadata->isEmpty());
    }

    public function test_itIsImmutable(): void
    {
        $original = EventMetadata::empty();
        $withCorrelation = $original->withCorrelationId('corr-1');

        $this->assertNull($original->getCorrelationId(), 'The original must not change.');
        $this->assertSame('corr-1', $withCorrelation->getCorrelationId());
        $this->assertFalse($withCorrelation->isEmpty());
    }

    public function test_eachFieldCanBeSetOnItsOwn(): void
    {
        $metadata = EventMetadata::empty()
            ->withCorrelationId('corr-1')
            ->withCausationId('cause-1')
            ->withActorId('user-7')
            ->withTenantId('tenant-a')
            ->withCustom(['channel' => 'api']);

        $this->assertSame('corr-1', $metadata->getCorrelationId());
        $this->assertSame('cause-1', $metadata->getCausationId());
        $this->assertSame('user-7', $metadata->getActorId());
        $this->assertSame('tenant-a', $metadata->getTenantId());
        $this->assertSame(['channel' => 'api'], $metadata->getCustom());
    }

    public function test_itRoundTripsThroughAnArray(): void
    {
        $metadata = EventMetadata::empty()
            ->withCorrelationId('corr-1')
            ->withCausationId('cause-1')
            ->withActorId('user-7')
            ->withTenantId('tenant-a')
            ->withCustom(['channel' => 'api']);

        $this->assertEquals($metadata, EventMetadata::fromArray($metadata->toArray()));
    }

    /**
     * An empty metadata set serialises to nothing rather than to a shape full
     * of nulls, so a store is not asked to keep four empty columns' worth of
     * JSON per event.
     */
    public function test_emptyMetadataSerialisesToAnEmptyArray(): void
    {
        $this->assertSame([], EventMetadata::empty()->toArray());
    }

    /**
     * A row written before metadata existed has no field at all, and must read
     * as empty rather than as an error.
     */
    public function test_itReadsAMissingOrMalformedValueAsEmpty(): void
    {
        $this->assertTrue(EventMetadata::fromArray([])->isEmpty());
        $this->assertTrue(EventMetadata::fromArray(['correlationId' => 42])->isEmpty());
    }
}
