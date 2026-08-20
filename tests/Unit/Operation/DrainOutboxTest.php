<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Operation;

use DomainFlow\EventSourcing\Clock\FrozenClock;
use DomainFlow\EventSourcing\Clock\SystemClock;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventDispatcherInterface;
use DomainFlow\EventSourcing\Interface\EventSubscriberInterface;
use DomainFlow\EventSourcing\Operation\DrainOutbox;
use DomainFlow\EventSourcing\Operation\DrainOutboxResult;
use DomainFlow\EventSourcing\Operation\DrainStopReason;
use DomainFlow\EventSourcing\Outbox\InMemoryOutboxStorage;
use DomainFlow\EventSourcing\Outbox\OutboxEntry;
use DomainFlow\EventSourcing\Outbox\OutboxRelay;
use DomainFlow\EventSourcing\Outbox\OutboxRelayResult;
use DomainFlow\EventSourcingCore\Provider\Unit\AnotherDummyEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The loop every relay operator writes today, with the three parts they get
 * wrong: backing off when there is nothing to do, terminating on a
 * bound so the same object serves a cron invocation and a daemon, and a stop
 * signal that does not drop the entry in hand.
 *
 * Nothing here sleeps. The sleeper is injected and, in the test that needs it,
 * advances the clock — which is what sleeping does.
 */
#[CoversClass(DrainOutbox::class)]
#[CoversClass(DrainOutboxResult::class)]
#[CoversClass(DrainStopReason::class)]
#[UsesClass(OutboxRelay::class)]
#[UsesClass(OutboxRelayResult::class)]
#[UsesClass(InMemoryOutboxStorage::class)]
#[UsesClass(OutboxEntry::class)]
#[UsesClass(EntityIdentifier::class)]
#[UsesClass(EventVersion::class)]
#[UsesClass(FrozenClock::class)]
#[UsesClass(SystemClock::class)]
final class DrainOutboxTest extends TestCase
{
    /**
     * A bounded run is what makes this usable from cron: without it the only
     * shape on offer is a daemon.
     */
    public function test_itStopsAfterTheConfiguredNumberOfPasses(): void
    {
        $outbox = $this->outboxWith(5);
        $drain = new DrainOutbox(
            new OutboxRelay($outbox, $this->collectingDispatcher(), batchSize: 1),
            maxPasses: 3
        );

        $result = $drain();

        $this->assertSame(3, $result->getPasses());
        $this->assertSame(3, $result->getDelivered());
        $this->assertSame(DrainStopReason::MaxPasses, $result->getStopReason());
        $this->assertSame(2, $outbox->countPending(), 'The rest waits for the next invocation.');
    }

    /**
     * The other bound, and the one that needs a clock: a run told to last a
     * minute has to end after about a minute even when the queue never empties.
     */
    public function test_itStopsWhenTheTimeBudgetIsSpent(): void
    {
        $clock = new FrozenClock('2026-01-01 12:00:00.000000');

        $drain = new DrainOutbox(
            new OutboxRelay(new InMemoryOutboxStorage(), $this->collectingDispatcher()),
            idleBackoffSeconds: 2,
            // Deliberately a multiple of the back-off, so the budget is
            // reached *exactly* rather than overshot: a comparison flipped
            // from "spent" to "overspent" then buys one extra pass, and this
            // is the arrangement that notices.
            maxSeconds: 6,
            clock: $clock,
            // Sleeping is the thing that makes time pass, so here it does.
            sleeper: static fn (int $seconds) => $clock->advance($seconds)
        );

        $result = $drain();

        $this->assertSame(DrainStopReason::MaxSeconds, $result->getStopReason());
        $this->assertSame(3, $result->getPasses(), 'Three idle passes spend the whole budget; a fourth would overspend it.');
    }

    /**
     * An empty pass must not spin the CPU, and a busy one must not be slowed
     * down — a backlog has to drain at whatever speed the store can serve it.
     */
    public function test_anIdlePassBacksOffAndABusyPassDoesNot(): void
    {
        $slept = [];

        $drain = new DrainOutbox(
            new OutboxRelay($this->outboxWith(1), $this->collectingDispatcher(), batchSize: 1),
            maxPasses: 2,
            idleBackoffSeconds: 7,
            sleeper: static function (int $seconds) use (&$slept): void {
                $slept[] = $seconds;
            }
        );

        $drain();

        $this->assertSame([7], $slept, 'The first pass delivered, so only the second one waited.');
    }

    /**
     * The property that makes a stop signal safe to wire to SIGTERM: the flag
     * is read between passes, so the pass in flight finishes and every entry
     * it claimed is accounted for. Stopping inside a pass would leave claimed
     * entries undelivered and unmarked, waiting out the lease before anyone
     * else could take them.
     */
    public function test_theStopFlagEndsTheRunWithoutDroppingTheEntriesInHand(): void
    {
        $outbox = $this->outboxWith(3);
        $delivered = [];

        $drain = null;
        $dispatcher = $this->dispatcherCalling(function (DomainEventInterface $event) use (&$delivered, &$drain): void {
            $delivered[] = $event;
            // A signal handler would do exactly this, at exactly this moment.
            $drain?->stop();
        });

        $drain = new DrainOutbox(new OutboxRelay($outbox, $dispatcher, batchSize: 3));

        $result = $drain();

        $this->assertCount(3, $delivered, 'The pass that was running finished.');
        $this->assertSame(1, $result->getPasses());
        $this->assertSame(3, $result->getDelivered());
        $this->assertSame(DrainStopReason::StopRequested, $result->getStopReason());
        $this->assertSame(0, $outbox->countPending());
    }

    /**
     * Asked to stop before it starts, it does nothing at all — a worker that
     * was already being shut down must not claim a batch on its way out.
     */
    public function test_aRunStoppedBeforeItBeginsClaimsNothing(): void
    {
        $outbox = $this->outboxWith(2);
        $drain = new DrainOutbox(new OutboxRelay($outbox, $this->collectingDispatcher()));
        $drain->stop();

        $result = $drain();

        $this->assertSame(0, $result->getPasses());
        $this->assertSame(DrainStopReason::StopRequested, $result->getStopReason());
        $this->assertSame(2, $outbox->countPending());
    }

    /**
     * The numbers are the reason this returns a result instead of logging:
     * abandoned entries mean a poisoned message, repeated failures mean a
     * consumer is down, and an operator alerts on the difference.
     */
    public function test_itAddsUpWhatEveryPassDid(): void
    {
        $outbox = $this->outboxWith(4);

        $drain = new DrainOutbox(
            new OutboxRelay($outbox, $this->failingDispatcher(), batchSize: 2),
            maxPasses: 2
        );

        $result = $drain();

        $this->assertSame(2, $result->getPasses());
        $this->assertSame(0, $result->getDelivered());
        $this->assertSame(4, $result->getFailed(), 'Two passes of two entries each, all refused.');
        $this->assertSame(0, $result->getAbandoned());
    }

    private function outboxWith(
        int $count
    ): InMemoryOutboxStorage {
        $outbox = new InMemoryOutboxStorage();
        $events = [];
        for ($i = 1; $i <= $count; $i++) {
            $events[] = new AnotherDummyEvent(EntityIdentifier::fromString('drain'), $i);
        }
        $outbox->enqueue($events);

        return $outbox;
    }

    private function collectingDispatcher(): EventDispatcherInterface
    {
        return $this->dispatcherCalling(static function (DomainEventInterface $event): void {
        });
    }

    private function failingDispatcher(): EventDispatcherInterface
    {
        return $this->dispatcherCalling(static function (DomainEventInterface $event): void {
            throw new RuntimeException('the consumer is down');
        });
    }

    /**
     * @param callable(DomainEventInterface): void $onDispatch
     * @return EventDispatcherInterface
     */
    private function dispatcherCalling(
        callable $onDispatch
    ): EventDispatcherInterface {
        return new class($onDispatch) implements EventDispatcherInterface {
            /** @var callable(DomainEventInterface): void */
            private $onDispatch;

            public function __construct(
                callable $onDispatch
            ) {
                $this->onDispatch = $onDispatch;
            }

            public function register(
                EventSubscriberInterface $subscriber
            ): void {
            }

            public function dispatch(
                DomainEventInterface $event
            ): void {
                ($this->onDispatch)($event);
            }

            public function dispatchAll(
                array $events
            ): void {
                foreach ($events as $event) {
                    $this->dispatch($event);
                }
            }
        };
    }
}
