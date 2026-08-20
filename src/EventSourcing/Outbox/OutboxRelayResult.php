<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Outbox;

/**
 * What one relay pass did.
 *
 * Returned rather than logged, so an operator can alert on it: a pass that
 * abandons entries is the shape of a poisoned message, and a pass that keeps
 * failing without abandoning is the shape of a consumer that is down.
 */
final readonly class OutboxRelayResult
{
    public function __construct(
        private int $delivered,
        private int $failed,
        private int $abandoned
    ) {
    }

    public function getDelivered(): int
    {
        return $this->delivered;
    }

    public function getFailed(): int
    {
        return $this->failed;
    }

    /**
     * Entries left pending because they have failed too often to keep trying.
     */
    public function getAbandoned(): int
    {
        return $this->abandoned;
    }

    public function isIdle(): bool
    {
        return $this->delivered === 0 && $this->failed === 0 && $this->abandoned === 0;
    }
}
