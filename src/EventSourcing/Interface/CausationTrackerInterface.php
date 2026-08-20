<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

/**
 * Told which event is being delivered, so that anything emitted while it is
 * can name what provoked it.
 *
 * Separate from MetadataProviderInterface because the two are used from
 * opposite sides: the dispatcher writes here, the enricher reads there, and a
 * framework may well want to implement one without the other.
 */
interface CausationTrackerInterface
{
    /**
     * @param string|null $eventId Null while nothing is being delivered.
     * @return void
     */
    public function causedBy(?string $eventId): void;
}
