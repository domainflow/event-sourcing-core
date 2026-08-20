<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Projector;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Clock\FrozenClock;
use DomainFlow\EventSourcing\Clock\SystemClock;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\GlobalEventPage;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Projector\CatchUpReader;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CatchUpReader::class)]
#[UsesClass(GlobalEventPage::class)]
#[UsesClass(EventVersion::class)]
#[UsesClass(EntityIdentifier::class)]
#[UsesClass(FrozenClock::class)]
#[UsesClass(SystemClock::class)]
final class CatchUpReaderTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-08-18 10:00:00');
    }

    /**
     * The failure this class exists for. Two writers overlap, the later
     * position becomes visible first, and a reader that trusted it would walk
     * past the earlier one forever.
     */
    public function test_anEventThatBecomesVisibleBehindTheFrontierIsStillDelivered(): void
    {
        $stream = new LateArrivingGlobalStream();
        $reader = $this->reader($stream);

        $stream->becomesVisible(6, $this->event('order-b', 1));

        $seen = [];
        $reader->read($this->collector($seen));
        $this->assertSame(['order-b#1'], $seen);

        // The writer that took position 5 finally commits.
        $stream->becomesVisible(5, $this->event('order-a', 1));

        $reader->read($this->collector($seen));

        $this->assertSame(
            ['order-b#1', 'order-a#1'],
            $seen,
            'A write that landed behind the frontier must still reach the handler.'
        );
    }

    public function test_reReadingTheSameStretchDoesNotDeliverAnythingTwice(): void
    {
        $stream = new LateArrivingGlobalStream();
        $reader = $this->reader($stream);

        $stream->becomesVisible(1, $this->event('order-a', 1));

        $seen = [];
        $reader->read($this->collector($seen));
        $reader->read($this->collector($seen));
        $reader->read($this->collector($seen));

        $this->assertSame(['order-a#1'], $seen, 'Re-reading is how the gap is closed; it must not cost duplicates.');
    }

    public function test_theSafePositionLagsUntilTheFrontierHasSettled(): void
    {
        $stream = new LateArrivingGlobalStream();
        $reader = $this->reader($stream);

        $stream->becomesVisible(6, $this->event('order-b', 1));

        $seen = [];
        $reader->read($this->collector($seen));

        $this->assertNull(
            $reader->getSafePosition(),
            'Persisting the frontier immediately is exactly the mistake that loses the late writer.'
        );

        $this->now = $this->now->modify('+5 seconds');
        $reader->read($this->collector($seen));

        $this->assertSame('6', $reader->getSafePosition(), 'Once nothing has moved for the grace period, the frontier can be trusted.');
    }

    /**
     * The honest bound. A write still in flight after the grace period is
     * lost, and no scheme built on a position cursor can do better without
     * commit order, which neither MySQL nor MongoDB exposes.
     */
    public function test_aWriteSlowerThanTheGracePeriodIsLost(): void
    {
        $stream = new LateArrivingGlobalStream();
        $reader = $this->reader($stream);

        $stream->becomesVisible(6, $this->event('order-b', 1));

        $seen = [];
        $reader->read($this->collector($seen));

        $this->now = $this->now->modify('+5 seconds');
        $reader->read($this->collector($seen));
        $this->assertSame('6', $reader->getSafePosition());

        $stream->becomesVisible(5, $this->event('order-a', 1));
        $reader->read($this->collector($seen));

        $this->assertSame(
            ['order-b#1'],
            $seen,
            'Documented limit: the grace period is what bounds how slow a write may be.'
        );
    }

    /**
     * A reader behind the head must not be rate-limited by the grace
     * period.
     *
     * The grace period exists so a write still in flight is not walked past.
     * Applied to every page, it also meant the reader waited one full grace
     * period before each further page — `pageSize / gapGraceSeconds` events per
     * second, 20 with the defaults, no matter how often read() was called. A
     * backlog of any size would otherwise be unreadable in practice.
     *
     * A full page says the reader is behind: everything below it was handed out
     * long ago, so there is nothing down there still arriving.
     */
    public function test_aBacklogSeveralPagesDeepIsDrainedWithoutTheClockMoving(): void
    {
        $stream = new LateArrivingGlobalStream();

        // Three full pages and a remainder, at pageSize 10.
        for ($position = 1; $position <= 32; $position++) {
            $stream->becomesVisible($position, $this->event('order-' . $position, 1));
        }

        $reader = $this->reader($stream);
        $frozenAt = $this->now;

        $seen = [];
        for ($cycle = 0; $cycle < 10; $cycle++) {
            $reader->read($this->collector($seen));
        }

        $this->assertSame($frozenAt, $this->now, 'Guard: this case is only meaningful with the clock standing still.');
        $this->assertCount(32, $seen, 'A reader in catch-up must not be limited to one page per grace period.');
    }

    /**
     * The other half of the same rule: at the head of the stream — where a page
     * comes back short — the grace period still applies, because that is where
     * a concurrent writer can still be holding a lower position.
     */
    public function test_aShortPageStillMakesTheReaderWaitBeforeTrustingTheFrontier(): void
    {
        $stream = new LateArrivingGlobalStream();
        $reader = $this->reader($stream);

        // Two events, pageSize 10: the page is short, so the reader is at the head.
        $stream->becomesVisible(1, $this->event('order-a', 1));
        $stream->becomesVisible(2, $this->event('order-b', 1));

        $seen = [];
        $reader->read($this->collector($seen));

        $this->assertNull($reader->getSafePosition(), 'At the head, the frontier is not yet trustworthy.');
    }

    public function test_readingAnEmptyStreamHandsOverNothingAndKeepsThePosition(): void
    {
        $reader = $this->reader(new LateArrivingGlobalStream());

        $seen = [];
        $this->assertSame(0, $reader->read($this->collector($seen)));
        $this->assertNull($reader->getSafePosition());
    }

    public function test_aReaderResumesFromAGivenPosition(): void
    {
        $stream = new LateArrivingGlobalStream();
        $stream->becomesVisible(1, $this->event('order-a', 1));
        $stream->becomesVisible(2, $this->event('order-b', 1));

        $reader = $this->reader($stream, startPosition: '1');

        $seen = [];
        $reader->read($this->collector($seen));

        $this->assertSame(['order-b#1'], $seen);
    }

    /**
     * The seam has a default, and a consumer who wants no clock injected must
     * not have to supply one. `SystemClock` provides that default,
     * which answers in UTC — rather than an inline `new DateTimeImmutable()`.
     */
    public function test_a_reader_built_without_a_clock_reads_the_stream(): void
    {
        $stream = new LateArrivingGlobalStream();
        $stream->becomesVisible(1, $this->event('order-a', 1));

        $seen = [];
        (new CatchUpReader($stream, pageSize: 10))->read($this->collector($seen));

        $this->assertSame(['order-a#1'], $seen);
    }

    /**
     * A `ClockInterface` is accepted beside a closure, so one clock object can
     * drive the reader and the outbox
     * lease alike.
     */
    public function test_a_reader_accepts_a_clock_object_as_well_as_a_closure(): void
    {
        $stream = new LateArrivingGlobalStream();
        $stream->becomesVisible(1, $this->event('order-a', 1));

        $seen = [];
        (new CatchUpReader($stream, pageSize: 10, clock: new FrozenClock('2026-08-18 10:00:00')))
            ->read($this->collector($seen));

        $this->assertSame(['order-a#1'], $seen);
    }

    private function reader(
        LateArrivingGlobalStream $stream,
        ?string $startPosition = null
    ): CatchUpReader {
        return new CatchUpReader(
            $stream,
            pageSize: 10,
            gapGraceSeconds: 5,
            startPosition: $startPosition,
            clock: fn (): DateTimeImmutable => $this->now
        );
    }

    /**
     * @param array<int, string> $seen
     * @return callable(DomainEventInterface): void
     */
    private function collector(
        array &$seen
    ): callable {
        return static function (DomainEventInterface $event) use (&$seen): void {
            $seen[] = (string) $event->getAggregateId() . '#' . $event->getVersion()->toInt();
        };
    }

    private function event(
        string $aggregateId,
        int $version
    ): DomainEventInterface {
        return new class($aggregateId, $version) implements DomainEventInterface {
            use HasEventMetadata;

            public function __construct(
                private readonly string $aggregateId,
                private readonly int $version
            ) {
            }

            public function getAggregateId(): EntityIdentifierInterface
            {
                return EntityIdentifier::fromString($this->aggregateId);
            }

            public function getOccurredOn(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-08-18 10:00:00');
            }

            public function getVersion(): EventVersion
            {
                return EventVersion::fromInt($this->version);
            }

            public function toArray(): array
            {
                return [];
            }

            public function setVersion(
                EventVersion $version
            ): void {
            }
        };
    }
}
