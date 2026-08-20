<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

/**
 * A marker interface for projections, based on event subscribers.
 */
interface ProjectorInterface extends EventSubscriberInterface
{
    /**
     * Resets the projection (read model) to a clean state.
     */
    public function reset(): void;

    /**
     * Replays a series of events to rebuild the projection.
     *
     * @param DomainEventInterface ...$events
     */
    public function replay(DomainEventInterface ...$events): void;

    /**
     * Returns whether this projector supports handling the given event class.
     *
     * @param string $eventClass
     */
    public function supports(string $eventClass): bool;

    /**
     * Optionally, get the name of the projector for logging/debugging.
     */
    public function getName(): string;
}
