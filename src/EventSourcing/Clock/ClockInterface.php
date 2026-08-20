<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Clock;

use DateTimeImmutable;

/**
 * Where this package reads the time.
 *
 * Shaped exactly like PSR-20, but **not** PSR-20: PHP types nominally, so a
 * `Psr\Clock\ClockInterface` implementation does not satisfy this one however
 * identical the signature is. Extending PSR-20 would mean requiring
 * `psr/clock`, and Core keeps its single production dependency.
 *
 * The seams that read time therefore accept a closure as well, which is all a
 * PSR-20 clock needs to fit:
 *
 * ```php
 * new CatchUpReader($storage, clock: fn (): DateTimeImmutable => $psrClock->now());
 * ```
 *
 * A consumer who wants one object for both can implement both interfaces; the
 * methods are the same.
 */
interface ClockInterface
{
    /**
     * @return DateTimeImmutable
     */
    public function now(): DateTimeImmutable;
}
