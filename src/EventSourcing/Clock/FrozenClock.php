<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Clock;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A clock a test moves by hand.
 *
 * The point is not tidiness. Time-dependent behaviour was previously tested
 * either by sleeping — which makes a suite slow and flaky in the same stroke —
 * or by reaching into the store and ageing rows, which tests the test's idea
 * of what expiry looks like rather than the code's. A clock that can be
 * advanced tests the real branch, instantly.
 */
final class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(
        DateTimeImmutable|string $now = '2026-01-01 00:00:00.000000'
    ) {
        $this->now = $now instanceof DateTimeImmutable
            ? $now->setTimezone(new DateTimeZone('UTC'))
            : new DateTimeImmutable($now, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * @param DateInterval|int $by A DateInterval, or seconds.
     * @return void
     */
    public function advance(
        DateInterval|int $by
    ): void {
        $this->now = $this->now->add(
            $by instanceof DateInterval ? $by : new DateInterval(sprintf('PT%dS', max(0, $by)))
        );
    }
}
