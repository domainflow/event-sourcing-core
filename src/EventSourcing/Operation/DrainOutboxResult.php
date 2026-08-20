<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Operation;

/**
 * What a whole drain did, as opposed to `OutboxRelayResult`, which is one pass.
 */
final readonly class DrainOutboxResult
{
    public function __construct(
        private int $passes,
        private int $delivered,
        private int $failed,
        private int $abandoned,
        private DrainStopReason $stopReason
    ) {
    }

    public function getPasses(): int
    {
        return $this->passes;
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
     * Entries that left the pending set for good because they failed too often.
     */
    public function getAbandoned(): int
    {
        return $this->abandoned;
    }

    public function getStopReason(): DrainStopReason
    {
        return $this->stopReason;
    }
}
