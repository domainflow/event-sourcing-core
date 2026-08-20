<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Operation;

use Closure;
use DateTimeImmutable;
use DomainFlow\EventSourcing\Clock\ClockInterface;
use DomainFlow\EventSourcing\Clock\SystemClock;
use DomainFlow\EventSourcing\Outbox\OutboxRelay;

/**
 * The relay loop, as an object instead of as something every consumer writes
 * again.
 */
final class DrainOutbox
{
    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $clock;

    private bool $stopRequested = false;

    /**
     * @param OutboxRelay $relay
     * @param int $maxPasses Stop after this many passes. Zero means no bound,
     *        which is the daemon shape.
     * @param int $maxSeconds Stop once this much wall-clock time has been
     *        spent. Zero means no bound. Checked between passes, so a run can
     *        overshoot by one pass — bounding it any tighter would mean
     *        abandoning work in progress.
     * @param int $idleBackoffSeconds How long to wait after a pass that
     *        delivered, failed and abandoned nothing.
     * @param ClockInterface|(Closure(): DateTimeImmutable)|null $clock Where
     *        the time budget is measured. A closure is accepted so a PSR-20
     *        clock fits without an adapter, as elsewhere in this package.
     * @param (Closure(int $seconds): void)|null $sleeper How to wait. Injected
     *        so a test can assert the back-off happened without a suite that
     *        sleeps; left out, this sleeps for real.
     */
    public function __construct(
        private readonly OutboxRelay $relay,
        private readonly int $maxPasses = 0,
        private readonly int $maxSeconds = 0,
        private readonly int $idleBackoffSeconds = 1,
        ClockInterface|Closure|null $clock = null,
        private readonly ?Closure $sleeper = null
    ) {
        $this->clock = match (true) {
            $clock instanceof ClockInterface => static fn (): DateTimeImmutable => $clock->now(),
            $clock instanceof Closure => $clock,
            default => static fn (): DateTimeImmutable => (new SystemClock())->now(),
        };
    }

    /**
     * Ask the drain to finish after the pass it is running.
     *
     * Safe to call from a signal handler, which is the point: `SIGTERM` during
     * a pass must not cost the entries that pass already claimed.
     *
     * @return void
     */
    public function stop(): void
    {
        $this->stopRequested = true;
    }

    /**
     * @return DrainOutboxResult
     */
    public function __invoke(): DrainOutboxResult
    {
        $startedAt = ($this->clock)();

        $passes = 0;
        $delivered = 0;
        $failed = 0;
        $abandoned = 0;

        // The loop condition *is* the stop reason, so there is no path out of
        // here without one.
        while (($reason = $this->reasonToStop($passes, $startedAt)) === null) {
            $result = $this->relay->run();

            $passes++;
            $delivered += $result->getDelivered();
            $failed += $result->getFailed();
            $abandoned += $result->getAbandoned();

            if ($result->isIdle()) {
                $seconds = max(0, $this->idleBackoffSeconds);

                // Without an injected sleeper this waits for real; with one, a
                // test asserts the back-off without the suite standing still.
                $this->sleeper === null ? sleep($seconds) : ($this->sleeper)($seconds);
            }
        }

        return new DrainOutboxResult($passes, $delivered, $failed, $abandoned, $reason);
    }

    /**
     * @param int $passes
     * @param DateTimeImmutable $startedAt
     * @return DrainStopReason|null
     */
    private function reasonToStop(
        int $passes,
        DateTimeImmutable $startedAt
    ): ?DrainStopReason {
        if ($this->stopRequested) {
            return DrainStopReason::StopRequested;
        }

        if ($this->maxPasses > 0 && $passes >= $this->maxPasses) {
            return DrainStopReason::MaxPasses;
        }

        $elapsed = ($this->clock)()->getTimestamp() - $startedAt->getTimestamp();

        return $this->maxSeconds > 0 && $elapsed >= $this->maxSeconds ? DrainStopReason::MaxSeconds : null;
    }
}
