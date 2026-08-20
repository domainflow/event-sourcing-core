<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Event\EventMetadata;
use DomainFlow\EventSourcing\Event\EventVersion;

interface DomainEventInterface
{
    /**
     * Retrieve the aggregate ID.
     *
     * @return EntityIdentifierInterface
     */
    public function getAggregateId(): EntityIdentifierInterface;

    /**
     * Get data for the event.
     *
     * @return DateTimeImmutable
     */
    public function getOccurredOn(): DateTimeImmutable;

    /**
     * The event version.
     *
     * @return EventVersion
     */
    public function getVersion(): EventVersion;

    /**
     * Convert the event to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * Set the event version.
     *
     * @param EventVersion $version
     * @return void
     */
    public function setVersion(EventVersion $version): void;

    /**
     * What the infrastructure knows about this event — correlation, causation,
     * actor, tenant.
     *
     * Never null: an event without metadata returns an empty set, so a caller
     * never has to ask whether there is any before asking what it says.
     *
     * `Trait\HasEventMetadata` implements this and `withMetadata()`; there is
     * nothing an event class can usefully say about how metadata is held, so
     * use the trait rather than writing them out.
     *
     * @return EventMetadata
     */
    public function getMetadata(): EventMetadata;

    /**
     * The same event with different metadata.
     *
     * Returns a copy rather than mutating, because an event's metadata is
     * decided once on the way to storage and an event already written must not
     * change underneath a caller holding it.
     *
     * @param EventMetadata $metadata
     * @return static
     */
    public function withMetadata(EventMetadata $metadata): static;
}
