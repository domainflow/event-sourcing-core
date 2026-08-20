<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;

interface SnapshotInterface
{
    /**
     * Retrieve the aggregate ID.
     *
     * @return EntityIdentifierInterface
     */
    public function getAggregateId(): EntityIdentifierInterface;

    /**
     * Retrieve the aggregate version.
     *
     * @return EventVersion
     */
    public function getVersion(): EventVersion;

    /**
     * Retrieve the state of the aggregate.
     *
     * @return array<string, mixed>
     */
    public function getState(): array;

    /**
     * Retrieve the date and time the snapshot was taken.
     *
     * @return OccurredOn
     */
    public function getOccurredOn(): OccurredOn;
}
