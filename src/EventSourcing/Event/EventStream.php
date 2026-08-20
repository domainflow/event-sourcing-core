<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Event;

use ArrayIterator;
use Countable;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use IteratorAggregate;
use Traversable;

/**
 * Represents a stream of events, allowing iteration and filtering.
 *
 * @template-implements IteratorAggregate<int, EventEntry>
 */
class EventStream implements IteratorAggregate, Countable
{
    /** @var EventEntry[] */
    private array $events;

    /**
     * @param EventEntry[] $events
     */
    public function __construct(
        array $events = []
    ) {
        $this->events = $events;
        // Compared as integers, not as EventVersion objects. PHP's object
        // comparison walks the properties, which happens to give the right
        // answer today and would silently give the wrong one the moment
        // EventVersion gained a second field.
        usort(
            $this->events,
            fn (EventEntry $a, EventEntry $b): int => $a->version->toInt() <=> $b->version->toInt()
        );
    }

    /**
     * Get an iterator for the events.
     *
     * @return Traversable<int, EventEntry>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->events);
    }

    /**
     * Get the number of events in the stream.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->events);
    }

    /**
     * Filter events by aggregate ID.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return EventStream
     */
    public function filterByAggregateId(
        EntityIdentifierInterface $aggregateId
    ): EventStream {
        return new self(
            array_filter(
                $this->events,
                fn (EventEntry $event) => $event->aggregateId->equals($aggregateId)
            )
        );
    }

    /**
     * Get events sorted by version.
     *
     * @return EventEntry[]
     */
    public function getEvents(): array
    {
        return $this->events;
    }
}
