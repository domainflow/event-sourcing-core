<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Event;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeInterface;
use DomainFlow\EventSourcing\Aggregate\AggregateId;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use JsonSerializable;
use Random\RandomException;

/**
 * An abstract event class implementing DomainEventInterface with
 * common functionality for source events.
 */
abstract class SourceEvent implements DomainEventInterface, JsonSerializable
{
    use HasEventMetadata;

    protected EntityIdentifierInterface $aggregateId;
    protected DateTimeInterface $occurredOn;
    protected EventVersion $version;
    protected EntityIdentifierInterface $eventId;

    /**
     * @throws RandomException
     */
    public function __construct(
        ?EntityIdentifierInterface $aggregateId,
        ?EntityIdentifierInterface $eventId,
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        $this->aggregateId = $aggregateId ?? AggregateId::generate();
        $this->eventId = $eventId ?? EventId::generate();
        $this->occurredOn = $occurredOn ?? OccurredOn::now();
        // Unassigned, not 1: the aggregate decides where in its stream this
        // event belongs and stamps the version in AggregateRoot::applyEvent().
        $this->version = $version ?? EventVersion::unassigned();

    }

    /**
     * Retrieve the aggregate ID.
     *
     * @return EntityIdentifierInterface
     */
    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->aggregateId;
    }

    /**
     * Get the date and time the event occurred.
     *
     * @throws DateMalformedStringException
     * @return DateTimeImmutable
     */
    public function getOccurredOn(): DateTimeImmutable
    {
        return $this->occurredOn instanceof DateTimeImmutable
            ? $this->occurredOn
            : new DateTimeImmutable($this->occurredOn->format('Y-m-d H:i:s.u'));
    }

    /**
     * Get the event version.
     *
     * @return EventVersion
     */
    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    /**
     * Set the event version manually.
     *
     * @param EventVersion $version
     * @return void
     */
    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }

    /**
     * Convert event data to an array. Subclasses can override.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'aggregateId' => (string) $this->aggregateId,
            'eventId' => (string) $this->eventId,
            'occurredOn' => $this->occurredOn->format('Y-m-d H:i:s.u'),
            'version' => $this->version->toInt(),
        ];
    }

    /**
     * Convert the event to a JSON-serializable array.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
