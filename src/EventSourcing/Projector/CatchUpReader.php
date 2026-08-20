<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Projector;

use Closure;
use DateTimeImmutable;
use DomainFlow\EventSourcing\Clock\ClockInterface;
use DomainFlow\EventSourcing\Clock\SystemClock;
use DomainFlow\EventSourcing\Event\GlobalEventPage;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;

/**
 * Reads the global stream in a way that survives concurrent writers.
 *
 */
final class CatchUpReader
{
    /** Positions at or below this have certainly been handled. */
    private ?string $safePosition;

    /** The newest position seen; not yet trusted. */
    private ?string $frontier = null;

    private ?DateTimeImmutable $frontierUnchangedSince = null;

    /**
     * Identities handed to the handler since the safe position, so that
     * re-reading the same stretch does not deliver them again.
     *
     * @var array<string, true>
     */
    private array $handled = [];

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    /**
     * @param EventStorageInterface $storage
     * @param int $pageSize How many events to read per cycle.
     * @param int $gapGraceSeconds How long a position may stay unexplained
     *        before it is treated as abandoned. Bounds both the reader's
     *        latency and the size of the write it can survive.
     * @param string|null $startPosition Where to resume; null starts at the
     *        beginning of the stream.
     * @param ClockInterface|(Closure(): DateTimeImmutable)|null $clock
     *        Injectable so a test does not have to sleep. This reader had the
     *        seam before there was a `ClockInterface`, in the shape of a
     *        closure; the closure form is still accepted so no consumer has to
     *        change, and a PSR-20 clock satisfies the interface directly.
     */
    public function __construct(
        private readonly EventStorageInterface $storage,
        private readonly int $pageSize = 100,
        private readonly int $gapGraceSeconds = 5,
        ?string $startPosition = null,
        ClockInterface|Closure|null $clock = null
    ) {
        $this->safePosition = $startPosition;
        $this->clock = match (true) {
            $clock instanceof ClockInterface => static fn (): DateTimeImmutable => $clock->now(),
            $clock instanceof Closure => $clock,
            default => static fn (): DateTimeImmutable => (new SystemClock())->now(),
        };
    }

    /**
     * Reads one page and hands every event not yet seen to the handler.
     *
     * @param callable(DomainEventInterface): void $handler
     * @return int How many events were handed over.
     */
    public function read(
        callable $handler
    ): int {
        $page = $this->storage->retrieveEventsFromPosition($this->safePosition, $this->pageSize);

        $delivered = $this->deliverUnseen($page, $handler);

        $this->trackFrontier($page->getNextPosition());

        if ($this->isBehind($page)) {

            $this->advanceSafePosition();

            return $delivered;
        }

        $this->advanceSafePositionIfSettled();

        return $delivered;
    }

    /**
     * Whether the reader is still catching up rather than sitting at the head
     * of the stream.
     *
     * A page that came back full means the store had more to give. A short one
     * means the reader has reached the end of what is visible — which is the
     * only place a concurrent writer can still be holding a position below the
     * frontier, and therefore the only place the grace period earns anything.
     *
     * @param GlobalEventPage $page
     * @return bool
     */
    private function isBehind(
        GlobalEventPage $page
    ): bool {
        return $this->pageSize > 0 && count($page->getEvents()) >= $this->pageSize;
    }

    /**
     * The position to persist. Resuming from it never skips an event; it may
     * repeat one.
     *
     * @return string|null
     */
    public function getSafePosition(): ?string
    {
        return $this->safePosition;
    }

    /**
     * @param GlobalEventPage $page
     * @param callable(DomainEventInterface): void $handler
     * @return int
     */
    private function deliverUnseen(
        GlobalEventPage $page,
        callable $handler
    ): int {
        $delivered = 0;

        foreach ($page->getEvents() as $event) {
            $identity = $this->identify($event);

            if (isset($this->handled[$identity])) {
                continue;
            }

            $handler($event);
            $this->handled[$identity] = true;
            $delivered++;
        }

        return $delivered;
    }

    /**
     * An event's identity, which is its place in its aggregate's stream. Unique
     * by construction: every adapter enforces it with a unique index.
     *
     * @param DomainEventInterface $event
     * @return string
     */
    private function identify(
        DomainEventInterface $event
    ): string {
        return (string) $event->getAggregateId() . '#' . $event->getVersion()->toInt();
    }

    /**
     * @param string|null $position
     * @return void
     */
    private function trackFrontier(
        ?string $position
    ): void {
        if ($position !== $this->frontier) {
            $this->frontier = $position;
            $this->frontierUnchangedSince = ($this->clock)();
        }
    }

    /**
     * Lets the safe position catch up once the frontier has been still long
     * enough that anything below it has either arrived or is not coming.
     *
     * @return void
     */
    private function advanceSafePositionIfSettled(): void
    {
        $unchangedSince = $this->frontierUnchangedSince;

        if ($this->frontier === $this->safePosition || $unchangedSince === null) {
            return;
        }

        $elapsed = ($this->clock)()->getTimestamp() - $unchangedSince->getTimestamp();

        if ($elapsed < $this->gapGraceSeconds) {
            return;
        }

        $this->advanceSafePosition();
    }

    /**
     * Moves the safe position up to the frontier.
     *
     * Callers establish that the frontier has actually moved. There is no
     * guard here for the case where it has not: the settled path checks it
     * before calling, and a full page cannot end at a position the reader
     * already treats as safe.
     *
     * @return void
     */
    private function advanceSafePosition(): void
    {
        $this->safePosition = $this->frontier;

        // Everything remembered is at or below the new safe position, so it
        // will never be read again and does not need remembering.
        $this->handled = [];
    }
}
