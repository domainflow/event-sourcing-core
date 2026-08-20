<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\ProcessManager;

/**
 * What one timeout pass did.
 *
 * Returned rather than logged, so an operator can alert on it — the same
 * reason `OutboxRelayResult` exists. Each count is a different thing going
 * wrong: contention that never drops means more workers than the schedule
 * needs, a skipped count that is not zero means states nobody claims are
 * accumulating, and failures are sagas whose timeout hook cannot complete.
 */
final readonly class ProcessManagerTimeoutResult
{
    public function __construct(
        private int $fired,
        private int $contended,
        private int $skipped,
        private int $failed
    ) {
    }

    /**
     * Processes whose timeout hook ran and whose result was stored.
     */
    public function getFired(): int
    {
        return $this->fired;
    }

    /**
     * Processes another worker advanced first. Not an error: the version check
     * is doing exactly what it is there for.
     */
    public function getContended(): int
    {
        return $this->contended;
    }

    /**
     * Overdue states the caller did not claim as any saga it knows.
     */
    public function getSkipped(): int
    {
        return $this->skipped;
    }

    /**
     * Processes whose timeout hook threw. The timeout is still set, so the next
     * pass will try again.
     */
    public function getFailed(): int
    {
        return $this->failed;
    }

    public function isIdle(): bool
    {
        return $this->fired === 0 && $this->contended === 0 && $this->skipped === 0 && $this->failed === 0;
    }
}
