<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Event;

use DomainFlow\EventSourcing\Interface\DomainEventInterface;

/**
 * One page of the global event stream, together with the position to resume
 * from.
 *
 * The position is what a projector persists. It is deliberately an opaque
 * string: each adapter has its own notion of a global position — an
 * auto-increment id, a sequence number, a counter — and a projector that
 * parses it has coupled itself to one backend. Store it, hand it back, do not
 * interpret it.
 *
 * Nothing here is a count. A projector that remembers "I have read 500 events"
 * and resumes with an offset skips or repeats events whenever a write lands
 * mid-scan, which is exactly the failure this type exists to prevent.
 */
final readonly class GlobalEventPage
{
    /**
     * @param DomainEventInterface[] $events
     * @param string|null $nextPosition The position to pass to the next read.
     *        Equal to the position that produced this page when the page is
     *        empty, so a projector that has caught up can persist an answer
     *        rather than a special case.
     */
    public function __construct(
        private array $events,
        private ?string $nextPosition
    ) {
    }

    /**
     * @return DomainEventInterface[]
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * @return string|null
     */
    public function getNextPosition(): ?string
    {
        return $this->nextPosition;
    }

    /**
     * Whether the reader has caught up with the stream.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->events === [];
    }
}
