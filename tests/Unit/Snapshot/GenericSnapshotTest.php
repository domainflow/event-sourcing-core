<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Snapshot;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntityIdentifier::class)]
#[CoversClass(EventVersion::class)]
#[CoversClass(OccurredOn::class)]
#[CoversClass(GenericSnapshot::class)]
final class GenericSnapshotTest extends TestCase
{
    public function test_aggregateIdIsReturnedCorrectly(): void
    {
        $aggregateId = EntityIdentifier::fromString('aggregate-123');
        $snapshot = new GenericSnapshot($aggregateId, EventVersion::fromInt(1), ['key' => 'value'], OccurredOn::fromString('2023-01-01'));
        $this->assertSame($aggregateId, $snapshot->getAggregateId());
    }

    public function test_versionIsReturnedCorrectly(): void
    {
        $snapshot = new GenericSnapshot(EntityIdentifier::fromString('aggregate-123'), EventVersion::fromInt(1), ['key' => 'value'], OccurredOn::fromString('2023-01-01'));
        $this->assertSame(1, $snapshot->getVersion()->toInt());
    }

    public function test_stateIsReturnedCorrectly(): void
    {
        $state = ['key' => 'value'];
        $snapshot = new GenericSnapshot(EntityIdentifier::fromString('aggregate-123'), EventVersion::fromInt(1), $state, OccurredOn::fromString('2023-01-01'));
        $this->assertSame($state, $snapshot->getState());
    }

    public function test_occurredOnIsReturnedCorrectly(): void
    {
        $occurredOn = OccurredOn::fromString('2023-01-01');
        $snapshot = new GenericSnapshot(EntityIdentifier::fromString('aggregate-123'), EventVersion::fromInt(1), ['key' => 'value'], $occurredOn);
        $this->assertSame($occurredOn, $snapshot->getOccurredOn());
    }

    public function test_stateCanBeEmptyArray(): void
    {
        $snapshot = new GenericSnapshot(EntityIdentifier::fromString('aggregate-123'), EventVersion::fromInt(1), [], OccurredOn::fromString('2023-01-01'));
        $this->assertSame([], $snapshot->getState());
    }

    public function test_versionCanBeZero(): void
    {
        $snapshot = new GenericSnapshot(EntityIdentifier::fromString('aggregate-123'), EventVersion::fromInt(0), ['key' => 'value'], OccurredOn::fromString('2023-01-01'));
        $this->assertSame(0, $snapshot->getVersion()->toInt());
    }
}
