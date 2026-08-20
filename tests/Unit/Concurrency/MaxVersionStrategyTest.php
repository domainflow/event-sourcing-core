<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Concurrency;

use DomainFlow\EventSourcing\Concurrency\MaxVersionStrategy;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Exception\ConcurrencyException;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventVersion::class)]
#[CoversClass(MaxVersionStrategy::class)]
final class MaxVersionStrategyTest extends TestCase
{
    private MaxVersionStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        // The storage double is built per test rather than here: most cases
        // want a stub, and a mock nobody sets an expectation on is a PHPUnit
        // notice — which under failOnRisky is a failing suite.
        $this->strategy = new MaxVersionStrategy();
    }

    /**
     * @throws ConcurrencyException
     */
    public function test_assertNoConflictWithEmptyEvents(): void
    {
        $innerStorage = $this->createMock(EventStorageInterface::class);
        $innerStorage
            ->expects($this->never())
            ->method('getCurrentMaxVersion');

        $this->strategy->assertNoConflict([], $innerStorage);

        $this->addToAssertionCount(1);
    }

    /**
     * @throws ConcurrencyException|Exception
     */
    public function test_assertNoConflictHappyPath(): void
    {
        $aggregateId = 'Agg-123';
        $fakeEvent = $this->createStub(DomainEventInterface::class);
        $mockIdentifier = $this->createStub(EntityIdentifierInterface::class);
        $mockIdentifier->method('__toString')->willReturn($aggregateId);

        $fakeEvent->method('getAggregateId')->willReturn($mockIdentifier);
        $fakeEvent->method('getVersion')->willReturn(EventVersion::fromInt(3));

        $innerStorage = $this->createMock(EventStorageInterface::class);
        $innerStorage
            ->expects($this->once())
            ->method('getCurrentMaxVersion')
            ->with($mockIdentifier)
            ->willReturn(EventVersion::fromInt(2));

        $this->strategy->assertNoConflict([$fakeEvent], $innerStorage);

        $this->addToAssertionCount(1);
    }

    /**
     * @throws Exception
     */
    public function test_assertNoConflictThrowsOnMismatch(): void
    {
        $aggregateId = 'Mismatch-Agg';

        $fakeEvent = $this->createStub(DomainEventInterface::class);

        $mockIdentifier = $this->createStub(EntityIdentifierInterface::class);
        $mockIdentifier->method('__toString')->willReturn($aggregateId);

        $fakeEvent->method('getAggregateId')->willReturn($mockIdentifier);
        $fakeEvent->method('getVersion')->willReturn(EventVersion::fromInt(5));

        $innerStorage = $this->createMock(EventStorageInterface::class);
        $innerStorage
            ->expects($this->once())
            ->method('getCurrentMaxVersion')
            ->with($mockIdentifier)
            ->willReturn(EventVersion::fromInt(2));

        $this->expectException(ConcurrencyException::class);
        $this->expectExceptionMessage('Concurrency conflict: expected version 3, got 5 for aggregate Mismatch-Agg');

        $this->strategy->assertNoConflict([$fakeEvent], $innerStorage);
    }

    /**
     * A batch may carry events for more than one aggregate —  made that
     * the unit of the write. Checking only $events[0] verified the first
     * aggregate and waved the rest through unexamined.
     *
     * @throws Exception
     */
    public function test_a_second_aggregate_in_the_batch_is_checked_too(): void
    {
        $events = [
            $this->event('Fresh-Agg', 1),
            $this->event('Stale-Agg', 7),
        ];

        $storage = $this->createStub(EventStorageInterface::class);
        $storage
            ->method('getCurrentMaxVersion')
            ->willReturnCallback(static fn (EntityIdentifierInterface $id): EventVersion => EventVersion::fromInt(
                (string) $id === 'Fresh-Agg' ? 0 : 2
            ));

        $this->expectException(ConcurrencyException::class);
        $this->expectExceptionMessage('Concurrency conflict: expected version 3, got 7 for aggregate Stale-Agg');

        $this->strategy->assertNoConflict($events, $storage);
    }

    /**
     * Only the first version was compared against the stream. A batch of
     * [4, 5, 7] on a stream at 3 lined up at its first event and nothing ever
     * looked at the hole between 5 and 7.
     *
     * @throws Exception
     */
    public function test_a_gap_inside_one_aggregates_run_is_rejected(): void
    {
        $events = [
            $this->event('Gappy-Agg', 4),
            $this->event('Gappy-Agg', 5),
            $this->event('Gappy-Agg', 7),
        ];

        $storage = $this->createStub(EventStorageInterface::class);
        $storage
            ->method('getCurrentMaxVersion')
            ->willReturn(EventVersion::fromInt(3));

        $this->expectException(ConcurrencyException::class);
        $this->expectExceptionMessage('Concurrency conflict: expected version 6, got 7 for aggregate Gappy-Agg');

        $this->strategy->assertNoConflict($events, $storage);
    }

    /**
     * The case that stops the fix from becoming "reject anything unsorted".
     * What the strategy owns is whether the batch forms a contiguous run from
     * where the stream currently stands — not the order the caller happened to
     * hand the events over in.
     *
     * @throws ConcurrencyException|Exception
     */
    public function test_events_out_of_order_but_contiguous_are_accepted(): void
    {
        $events = [
            $this->event('Shuffled-Agg', 6),
            $this->event('Shuffled-Agg', 4),
            $this->event('Shuffled-Agg', 5),
        ];

        $storage = $this->createStub(EventStorageInterface::class);
        $storage
            ->method('getCurrentMaxVersion')
            ->willReturn(EventVersion::fromInt(3));

        $this->strategy->assertNoConflict($events, $storage);

        $this->addToAssertionCount(1);
    }

    /**
     * One call per aggregate, not one per event. The honest cost of checking
     * the whole batch is one read per stream it touches; anything more is the
     * decorator making the write expensive to protect it.
     *
     * @throws ConcurrencyException|Exception
     */
    public function test_each_aggregate_is_read_exactly_once(): void
    {
        $events = [
            $this->event('Agg-A', 1),
            $this->event('Agg-B', 1),
            $this->event('Agg-A', 2),
            $this->event('Agg-B', 2),
        ];

        $innerStorage = $this->createMock(EventStorageInterface::class);
        $innerStorage
            ->expects($this->exactly(2))
            ->method('getCurrentMaxVersion')
            ->willReturn(EventVersion::unassigned());

        $this->strategy->assertNoConflict($events, $innerStorage);

        $this->addToAssertionCount(1);
    }

    /**
     * @throws Exception
     */
    private function event(
        string $aggregateId,
        int $version
    ): DomainEventInterface {
        $identifier = $this->createStub(EntityIdentifierInterface::class);
        $identifier->method('__toString')->willReturn($aggregateId);

        $event = $this->createStub(DomainEventInterface::class);
        $event->method('getAggregateId')->willReturn($identifier);
        $event->method('getVersion')->willReturn(EventVersion::fromInt($version));

        return $event;
    }
}
