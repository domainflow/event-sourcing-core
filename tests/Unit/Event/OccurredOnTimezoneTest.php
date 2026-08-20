<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Event;

use DateTimeImmutable;
use DateTimeZone;
use DomainFlow\EventSourcing\Event\OccurredOn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * An event timestamp has to mean the same thing everywhere.
 *
 * The stored format carries no offset, so the only thing keeping two services
 * comparable is that both normalise to UTC. These run under a deliberately
 * awkward runtime timezone — one with daylight saving, so the failure is not
 * merely a fixed offset.
 */
#[CoversClass(OccurredOn::class)]
final class OccurredOnTimezoneTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        $this->originalTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
    }

    public function test_theSameInstantIsStoredIdenticallyWhateverTheRuntimeTimezone(): void
    {
        date_default_timezone_set('UTC');
        $inUtc = (string) OccurredOn::fromString('@1755500000');

        date_default_timezone_set('Europe/Berlin');
        $inBerlin = (string) OccurredOn::fromString('@1755500000');

        date_default_timezone_set('Pacific/Kiritimati');
        $inKiritimati = (string) OccurredOn::fromString('@1755500000');

        $this->assertSame($inUtc, $inBerlin, 'Two services with different date.timezone settings must write the same value.');
        $this->assertSame($inUtc, $inKiritimati);
    }

    public function test_aLocalWallClockTimeIsConvertedRatherThanRelabelled(): void
    {
        date_default_timezone_set('Europe/Berlin');

        // 12:00 in Berlin in August is 10:00 UTC. Storing "12:00" would be
        // relabelling, which is the failure: the value would read as UTC and
        // be two hours wrong.
        //
        // Through the constructor, not fromString(): the two paths have
        // different semantics.
        // A string an application hands in is a local wall-clock time and is
        // converted; a string coming back out of storage is already UTC and is
        // read as such. Both were the constructor's behaviour before, which is
        // what made every stored value shift on the way back.
        $occurredOn = new OccurredOn('2026-08-18 12:00:00');

        $this->assertSame('2026-08-18 10:00:00.000000', (string) $occurredOn);
    }

    /**
     * The other half of that split, stated so neither can drift back.
     */
    public function test_fromStringReadsAStoredValueAsUtcRatherThanAsLocalTime(): void
    {
        date_default_timezone_set('Europe/Berlin');

        $occurredOn = OccurredOn::fromString('2026-08-18 12:00:00');

        $this->assertSame(
            '2026-08-18 12:00:00.000000',
            (string) $occurredOn,
            'A value out of storage is UTC by contract and must not be converted a second time.'
        );
    }

    public function test_anExplicitOffsetIsRespected(): void
    {
        date_default_timezone_set('UTC');

        $occurredOn = OccurredOn::fromString('2026-08-18 12:00:00+02:00');

        $this->assertSame('2026-08-18 10:00:00.000000', (string) $occurredOn);
    }

    public function test_theResultIsActuallyInUtc(): void
    {
        date_default_timezone_set('Europe/Berlin');

        $this->assertSame('UTC', OccurredOn::now()->getTimezone()->getName());
    }

    /**
     * Reading back a stored value must not shift it again. A stored string is
     * already UTC, and a second normalisation pass that treated it as local
     * time would move every timestamp on every read.
     *
     * This used to assert `assertNotSame` — the defect written down as
     * a limitation, under a name that claimed the opposite. The stored format
     * is UTC, so the read side must parse it accordingly.
     */
    public function test_readingBackAStoredValueDoesNotShiftItAgain(): void
    {
        date_default_timezone_set('UTC');
        $stored = (string) OccurredOn::fromString('2026-08-18 10:00:00');

        date_default_timezone_set('Europe/Berlin');
        $roundTripped = (string) OccurredOn::fromString($stored);

        $this->assertSame(
            $stored,
            $roundTripped,
            'A stored value is UTC by contract, so reading it on a runtime in another zone must not move it.'
        );
    }

    /**
     * And it must keep denoting the same instant, not merely the same string —
     * a value that is relabelled rather than converted would satisfy the
     * assertion above while pointing at a different moment.
     */
    public function test_aStoredValueDenotesTheSameInstantOnAnyRuntime(): void
    {
        date_default_timezone_set('UTC');
        $stored = (string) OccurredOn::fromString('2026-08-18 10:00:00');
        $inUtc = OccurredOn::fromString($stored)->getTimestamp();

        date_default_timezone_set('Pacific/Kiritimati');
        $inKiritimati = OccurredOn::fromString($stored)->getTimestamp();

        $this->assertSame($inUtc, $inKiritimati);
    }

    public function test_microsecondsSurviveTheConversion(): void
    {
        date_default_timezone_set('Europe/Berlin');

        $occurredOn = new OccurredOn('2026-08-18 12:00:00.123456');

        $this->assertSame('2026-08-18 10:00:00.123456', (string) $occurredOn);
    }

    public function test_anInputZoneCanBeStatedExplicitly(): void
    {
        date_default_timezone_set('UTC');

        $occurredOn = new OccurredOn('2026-08-18 12:00:00', new DateTimeZone('Europe/Berlin'));

        $this->assertSame('2026-08-18 10:00:00.000000', (string) $occurredOn);
        $this->assertSame(
            (new DateTimeImmutable('2026-08-18 12:00:00', new DateTimeZone('Europe/Berlin')))->getTimestamp(),
            $occurredOn->getTimestamp(),
            'Converting must not change which instant this is.'
        );
    }
}
