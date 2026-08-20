<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Clock;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The system clock, in UTC.
 *
 * UTC rather than the runtime's zone, because every timestamp this package
 * stores is UTC and the stored format carries no offset to say so. A
 * clock that handed back local time would put the defect back one layer up,
 * where it would be even harder to see.
 */
final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
