<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Snapshot;

use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;

final readonly class GenericSnapshot implements SnapshotInterface
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        private EntityIdentifierInterface $aggregateId,
        private EventVersion $version,
        private array $state,
        private OccurredOn $occurredOn
    ) {
    }

    /**
     * Get the aggregate ID of the snapshot.
     *
     * @return EntityIdentifierInterface
     */
    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->aggregateId;
    }

    /**
     * Get the version of the snapshot.
     *
     * @return EventVersion
     */
    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    /**
     * Get the state of the snapshot.
     *
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * Get the date and time the snapshot occurred.
     *
     * @return OccurredOn
     */
    public function getOccurredOn(): OccurredOn
    {
        return $this->occurredOn;
    }
}
