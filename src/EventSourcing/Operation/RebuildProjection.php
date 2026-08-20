<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Operation;

use Closure;
use DateTimeImmutable;
use DomainFlow\EventSourcing\Clock\ClockInterface;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\ProjectorInterface;
use DomainFlow\EventSourcing\Projector\CatchUpReader;

/**
 * Rebuilding a projection from the global stream.
 */
final readonly class RebuildProjection
{
    /**
     * @param EventStorageInterface $storage
     * @param int $pageSize How many events to read per cycle.
     * @param int $gapGraceSeconds How long a position may stay unexplained
     *        before the reader trusts it. See `CatchUpReader`.
     * @param ClockInterface|(Closure(): DateTimeImmutable)|null $clock Passed
     *        to the reader, so a test moves time instead of waiting for it.
     */
    public function __construct(
        private EventStorageInterface $storage,
        private int $pageSize = 100,
        private int $gapGraceSeconds = 5,
        private ClockInterface|Closure|null $clock = null
    ) {
    }

    /**
     * Clear the projection, then replay the stream into it.
     *
     * @param ProjectorInterface $projector
     * @return RebuildProjectionResult
     */
    public function __invoke(
        ProjectorInterface $projector
    ): RebuildProjectionResult {
        $reader = new CatchUpReader(
            $this->storage,
            $this->pageSize,
            $this->gapGraceSeconds,
            // The beginning of the stream, always. That is what makes this a
            // rebuild rather than a catch-up.
            null,
            $this->clock
        );

        $projector->reset();

        $replayed = 0;
        $handler = static function (DomainEventInterface $event) use ($projector, &$replayed): void {
            if (!$projector->supports($event::class)) {
                return;
            }

            $projector->replay($event);
            $replayed++;
        };

        do {
            $handed = $reader->read($handler);
        } while ($handed > 0);

        return new RebuildProjectionResult($replayed, $reader->getSafePosition());
    }
}
