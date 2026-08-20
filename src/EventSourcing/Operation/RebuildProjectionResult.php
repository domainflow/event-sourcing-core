<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Operation;

/**
 * What a rebuild replayed, and where it got to.
 *
 * The position is returned rather than stored: where a projection's cursor
 * lives belongs to the consumer, and a rebuild that wrote it somewhere of its
 * own choosing would be deciding that for them.
 */
final readonly class RebuildProjectionResult
{
    /**
     * @param int $eventsReplayed
     * @param string|null $position The reader's safe position. Null means
     *        "start from the beginning", which is the conservative answer
     *        rather than a missing one — see `RebuildProjection::__invoke()`.
     */
    public function __construct(
        private int $eventsReplayed,
        private ?string $position
    ) {
    }

    public function getEventsReplayed(): int
    {
        return $this->eventsReplayed;
    }

    public function getPosition(): ?string
    {
        return $this->position;
    }
}
