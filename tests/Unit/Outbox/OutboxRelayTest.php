<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Outbox;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventDispatcherInterface;
use DomainFlow\EventSourcing\Outbox\InMemoryOutboxStorage;
use DomainFlow\EventSourcing\Outbox\OutboxEntry;
use DomainFlow\EventSourcing\Outbox\OutboxRelay;
use DomainFlow\EventSourcing\Outbox\OutboxRelayResult;
use DomainFlow\EventSourcingCore\Provider\Unit\AnotherDummyEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(OutboxRelay::class)]
#[CoversClass(OutboxRelayResult::class)]
#[UsesClass(InMemoryOutboxStorage::class)]
#[UsesClass(OutboxEntry::class)]
#[UsesClass(EntityIdentifier::class)]
#[UsesClass(EventVersion::class)]
final class OutboxRelayTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function test_aPassDeliversWhatIsPendingAndClearsIt(): void
    {
        $outbox = new InMemoryOutboxStorage();
        $outbox->enqueue([
            new AnotherDummyEvent(EntityIdentifier::fromString('relay-a'), 1),
            new AnotherDummyEvent(EntityIdentifier::fromString('relay-a'), 2),
        ]);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->exactly(2))->method('dispatch');

        $result = (new OutboxRelay($outbox, $dispatcher))->run();

        $this->assertSame(2, $result->getDelivered());
        $this->assertSame(0, $outbox->countPending(), 'A delivered entry is done with.');
    }

    /**
     * One consumer being down must not hold up everyone else's traffic, and
     * the undelivered entry must stay owed rather than quietly disappear.
     *
     * @throws Exception
     */
    public function test_aFailedDeliveryStaysPendingAndDoesNotStopTheRest(): void
    {
        $outbox = new InMemoryOutboxStorage();
        $outbox->enqueue([
            new AnotherDummyEvent(EntityIdentifier::fromString('relay-fails'), 1),
            new AnotherDummyEvent(EntityIdentifier::fromString('relay-works'), 1),
        ]);

        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            static function (DomainEventInterface $event): void {
                if ((string) $event->getAggregateId() === 'relay-fails') {
                    throw new RuntimeException('consumer is down');
                }
            }
        );

        $result = (new OutboxRelay($outbox, $dispatcher))->run();

        $this->assertSame(1, $result->getDelivered());
        $this->assertSame(1, $result->getFailed());
        $this->assertSame(1, $outbox->countPending(), 'The failed entry is still owed.');
    }

    /**
     * @throws Exception
     */
    public function test_anEntryThatKeepsFailingIsEventuallyAbandonedRatherThanRetriedForever(): void
    {
        $outbox = new InMemoryOutboxStorage();
        $outbox->enqueue([new AnotherDummyEvent(EntityIdentifier::fromString('relay-poison'), 1)]);

        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('poison'));

        $relay = new OutboxRelay($outbox, $dispatcher, batchSize: 10, maxAttempts: 2);

        $this->assertSame(1, $relay->run()->getFailed());
        $this->assertSame(1, $relay->run()->getFailed());

        $result = $relay->run();

        $this->assertSame(0, $result->getFailed());
        $this->assertSame(1, $result->getAbandoned(), 'Past its attempt limit the entry is no longer dispatched.');
        $this->assertSame(0, $outbox->countPending(), 'And it leaves the pending set, so countPending() can drain.');
        $this->assertSame(1, $outbox->countAbandoned(), 'It stays visible, in the place that says what happened to it.');
    }

    /**
     * An entry the relay gives up on must not be marked *failed*, because that
     * puts it straight back into the pending set. It would then be
     * re-claimed on the next pass and every pass after that, forever — burning
     * a slot of every batch on something already abandoned, and leaving
     * `countPending()` unable to drain.
     *
     * @throws Exception
     */
    public function test_anAbandonedEntryIsNotClaimedAgainOnTheNextPass(): void
    {
        $outbox = new InMemoryOutboxStorage();
        $outbox->enqueue([
            new AnotherDummyEvent(EntityIdentifier::fromString('relay-poison'), 1),
            new AnotherDummyEvent(EntityIdentifier::fromString('relay-healthy'), 1),
        ]);

        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(
            static function (DomainEventInterface $event): void {
                if ((string) $event->getAggregateId() === 'relay-poison') {
                    throw new RuntimeException('poison');
                }
            }
        );

        $relay = new OutboxRelay($outbox, $dispatcher, batchSize: 10, maxAttempts: 1);

        $first = $relay->run();
        $this->assertSame(1, $first->getDelivered());
        $this->assertSame(1, $first->getFailed());

        $second = $relay->run();
        $this->assertSame(1, $second->getAbandoned());

        // The pass that matters: with nothing left to do, the relay is idle
        // rather than picking the poisoned entry up again.
        $third = $relay->run();

        $this->assertTrue($third->isIdle(), 'An abandoned entry must not come back on the next pass.');
        $this->assertSame(0, $outbox->countPending());
        $this->assertSame(1, $outbox->countAbandoned());
    }

    /**
     * @throws Exception
     */
    public function test_anAttemptLimitOfZeroNeverGivesUp(): void
    {
        $outbox = new InMemoryOutboxStorage();
        $outbox->enqueue([new AnotherDummyEvent(EntityIdentifier::fromString('relay-forever'), 1)]);

        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willThrowException(new RuntimeException('still down'));

        $relay = new OutboxRelay($outbox, $dispatcher, batchSize: 10, maxAttempts: 0);

        for ($pass = 0; $pass < 5; $pass++) {
            $this->assertSame(1, $relay->run()->getFailed());
        }

        $this->assertSame(0, $relay->run()->getAbandoned());
    }

    /**
     * @throws Exception
     */
    public function test_anEmptyOutboxMakesForAnIdlePass(): void
    {
        $relay = new OutboxRelay(new InMemoryOutboxStorage(), $this->createStub(EventDispatcherInterface::class));

        $result = $relay->run();

        $this->assertTrue($result->isIdle());
        $this->assertSame(0, $result->getDelivered());
        $this->assertSame(0, $result->getFailed());
        $this->assertSame(0, $result->getAbandoned());
    }
}
