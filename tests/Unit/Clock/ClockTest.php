<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Clock;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainFlow\EventSourcing\Clock\FrozenClock;
use DomainFlow\EventSourcing\Clock\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemClock::class)]
#[CoversClass(FrozenClock::class)]
final class ClockTest extends TestCase
{
    /**
     * Every timestamp this package stores is UTC and the stored format carries
     * no offset to say so. A clock handing back local time would put
     * that defect back one layer up.
     */
    public function test_the_system_clock_answers_in_utc_whatever_the_runtime(): void
    {
        $runtimeTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('Europe/Berlin');

            $this->assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
        } finally {
            date_default_timezone_set($runtimeTimezone);
        }
    }

    public function test_a_frozen_clock_does_not_move_on_its_own(): void
    {
        $clock = new FrozenClock('2026-01-01 12:00:00.000000');

        $this->assertSame('2026-01-01 12:00:00.000000', $clock->now()->format('Y-m-d H:i:s.u'));
        $this->assertSame($clock->now()->format('Y-m-d H:i:s.u'), $clock->now()->format('Y-m-d H:i:s.u'));
    }

    public function test_a_frozen_clock_advances_by_seconds(): void
    {
        $clock = new FrozenClock('2026-01-01 12:00:00.000000');

        $clock->advance(90);

        $this->assertSame('2026-01-01 12:01:30.000000', $clock->now()->format('Y-m-d H:i:s.u'));
    }

    public function test_a_frozen_clock_advances_by_an_interval(): void
    {
        $clock = new FrozenClock('2026-01-01 12:00:00.000000');

        $clock->advance(new DateInterval('PT2H'));

        $this->assertSame('2026-01-01 14:00:00.000000', $clock->now()->format('Y-m-d H:i:s.u'));
    }

    /**
     * A negative advance would be a clock going backwards, which no caller
     * means and which would make a lease look renewed.
     */
    public function test_a_frozen_clock_does_not_go_backwards(): void
    {
        $clock = new FrozenClock('2026-01-01 12:00:00.000000');

        $clock->advance(-60);

        $this->assertSame('2026-01-01 12:00:00.000000', $clock->now()->format('Y-m-d H:i:s.u'));
    }

    /**
     * Given a moment in another zone, it is the instant that is kept.
     */
    public function test_a_frozen_clock_normalises_what_it_is_given_to_utc(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-01-01 09:00:00.000000', new DateTimeZone('Asia/Tokyo')));

        $this->assertSame('2026-01-01 00:00:00.000000', $clock->now()->format('Y-m-d H:i:s.u'));
        $this->assertSame('UTC', $clock->now()->getTimezone()->getName());
    }
}
