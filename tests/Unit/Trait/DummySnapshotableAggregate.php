<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Trait;

use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\SnapshotableAggregateInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Trait\SnapshotableAggregateTrait;

/**
 * Dummy implementation of a snapshotable aggregate for
 * testing / test coverage purposes only.
 */
class DummySnapshotableAggregate extends AggregateRoot implements SnapshotableAggregateInterface
{
    use SnapshotableAggregateTrait;

    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function shouldTakeSnapshot(): bool
    {
        return true;
    }

    public function getSnapshotState(): array
    {
        return ['something' => 'useful'];
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString('dummy-snapshotable');
    }

    public function applySnapshot(
        SnapshotInterface $snapshot
    ): void {
    }
}
