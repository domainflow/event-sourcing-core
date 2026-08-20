<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Event;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * When an event happened, always in UTC.
 *
 * The stored format stays `'Y-m-d H:i:s.u'` — no offset, no marker — because
 * changing it would rewrite every row in every adapter. What changes is that
 * the value behind it is now unambiguous: normalised to UTC on construction,
 * whatever the runtime's `date.timezone` happens to be.
 *
 * Without that, two services in one cluster with different `date.timezone`
 * settings write non-comparable timestamps into the same column, and a
 * deployment moving from UTC to a zone with daylight saving writes values that
 * are not even unique within one service — 02:00 to 03:00 exists twice a year.
 *
 * This matters because `occurred_on` is the field an operator sorts by when
 * auditing or debugging, and a timestamp nobody can place is worse than no
 * timestamp at all.
 */
final class OccurredOn extends DateTimeImmutable
{
    /**
     * @param string $datetime
     * @param DateTimeZone|null $timezone Interpreted as the zone the input is
     *        *in*, not the zone it is stored in. A value that already carries
     *        an offset keeps its own; everything else is read in this zone and
     *        then converted.
     * @throws DateMalformedStringException
     */
    public function __construct(
        string $datetime = 'now',
        ?DateTimeZone $timezone = null
    ) {
        parent::__construct($datetime, $timezone);

        // setTimezone() on the parent returns a DateTimeImmutable, not a self,
        // so the shift is applied by re-reading the converted value.
        $utc = new DateTimeZone('UTC');

        if ($this->getTimezone()->getName() !== $utc->getName()) {
            parent::__construct(
                (new DateTimeImmutable($datetime, $timezone))->setTimezone($utc)->format('Y-m-d H:i:s.u'),
                $utc
            );
        }
    }

    /**
     * Read a value that came out of storage.
     *
     * The zone is stated rather than inferred. Everything this library
     * writes is UTC, and the stored format has no offset to carry that fact, so
     * a runtime in another zone used to read the string as local time and move
     * it — on every read, compounding each time a value was read and written
     * back.
     *
     * A string that carries its own offset keeps it; the constructor's zone
     * argument only applies where the input is silent about it.
     *
     * Use the constructor instead for a wall-clock time from application code:
     * there the input really is local and converting it is the point.
     *
     * @throws DateMalformedStringException
     */
    public static function fromString(
        string $value
    ): self {
        return new self($value, new DateTimeZone('UTC'));
    }

    /**
     * Current timestamp.
     *
     * @return self
     */
    public static function now(): self
    {
        return new self('now');
    }

    /**
     * Create from DateTimeImmutable.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->format('Y-m-d H:i:s.u');
    }
}
