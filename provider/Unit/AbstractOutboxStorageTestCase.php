<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Unit;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Interface\OutboxStorageInterface;
use DomainFlow\EventSourcing\Outbox\OutboxEntry;
use PHPUnit\Framework\TestCase;

/**
 * The behavioural contract every OutboxStorageInterface implementation owes.
 *
 * An outbox is only worth having if claiming is exclusive and marking is
 * honest, so those are what this case is about — not the storage mechanics.
 */
abstract class AbstractOutboxStorageTestCase extends TestCase
{
    abstract protected function getOutbox(): OutboxStorageInterface;

    /**
     * Two relays' views of one outbox, honouring $leaseSeconds, whose own
     * clocks are $skewSeconds apart: the first believes it is now, the second
     * believes it is $skewSeconds later — or earlier, when the skew is
     * negative. Both directions occur in the cases below, because a lease can
     * be broken from either side.
     *
     * An implementation with no relay-local notion of now returns two plain
     * views and ignores the skew — MySQL computes the lease in the database,
     * and the in-memory reference never expires a claim at all. That is the
     * correct answer rather than a dodge: the case asks whether a relay's own
     * clock can decide a lease, and "there is no such clock" is the strongest
     * available no. What it must never be is a view that lets the skew
     * through.
     *
     * Both views must address the same store, so a single-process
     * implementation returns the same instance twice.
     *
     * @param int $leaseSeconds
     * @param int $skewSeconds
     * @return array{0: OutboxStorageInterface, 1: OutboxStorageInterface}
     */
    abstract protected function getRelaysWithSkewedClocks(
        int $leaseSeconds,
        int $skewSeconds
    ): array;

    public function test_enqueuedEventsAreCountedAsPending(): void
    {
        $outbox = $this->getOutbox();
        $aggregateId = EntityIdentifier::fromString('OutboxAggregate');

        $this->assertSame(0, $outbox->countPending());

        $outbox->enqueue([
            new AnotherDummyEvent($aggregateId, 1),
            new AnotherDummyEvent($aggregateId, 2),
        ]);

        $this->assertSame(2, $outbox->countPending());
    }

    public function test_enqueuingNothingIsANoOp(): void
    {
        $outbox = $this->getOutbox();

        $outbox->enqueue([]);

        $this->assertSame(0, $outbox->countPending(), 'An empty batch must not cost a write.');
    }

    public function test_reservingHandsBackTheEventThatWasEnqueued(): void
    {
        $outbox = $this->getOutbox();
        $aggregateId = EntityIdentifier::fromString('OutboxRoundTrip');

        $outbox->enqueue([new AnotherDummyEvent($aggregateId, 7)]);

        $reserved = $outbox->reserve(10);

        $this->assertCount(1, $reserved);
        $this->assertSame('OutboxRoundTrip', (string) $reserved[0]->getEvent()->getAggregateId());
        $this->assertSame(7, $reserved[0]->getEvent()->getVersion()->toInt());
        $this->assertSame(0, $reserved[0]->getAttempts());
    }

    /**
     * The property the whole pattern rests on. Two relays claiming the same
     * entry turns at-least-once into a guaranteed duplicate on every pass.
     */
    public function test_anEntryIsNeverHandedToTwoRelaysAtOnce(): void
    {
        $outbox = $this->getOutbox();
        $aggregateId = EntityIdentifier::fromString('OutboxExclusive');

        $outbox->enqueue([
            new AnotherDummyEvent($aggregateId, 1),
            new AnotherDummyEvent($aggregateId, 2),
        ]);

        $first = $outbox->reserve(10);
        $second = $outbox->reserve(10);

        $this->assertCount(2, $first);
        $this->assertSame([], $second, 'Everything was already claimed by the first relay.');
    }

    /**
     * The lease boundary belongs to the store's clock, not to the relay's
     *.
     *
     * A fleet of relays is the normal deployment — it is the whole reason the
     * lease exists — and the lease is the one thing every member of that fleet
     * has to agree on. Measure it with each relay's own clock and two hosts a
     * minute apart, which is unremarkable for containers without strict NTP,
     * disagree about when a claim lapsed: the relay that runs fast takes an
     * entry the slow one is still delivering, so at-least-once becomes a
     * duplicate on every cycle, and nothing anywhere reports it.
     *
     * Here the fast relay's clock is past the lease and the store's is not.
     * The store decides, so it must be refused.
     *
     * With the clock read from the system there is only ever one clock in a
     * test process, and two clocks that
     * disagree is the only arrangement in which the defect is visible. That is
     * why it survived every lease test the suite already had.
     */
    public function test_aRelayWhoseClockRunsAheadCannotTakeALiveClaim(): void
    {
        $lease = 60;

        [$onTime, $ahead] = $this->getRelaysWithSkewedClocks($lease, $lease + 1);

        $onTime->enqueue([new AnotherDummyEvent(EntityIdentifier::fromString('OutboxSkewedFleet'), 1)]);

        $this->assertCount(1, $onTime->reserve(1), 'Precondition: the on-time relay holds the claim.');

        $this->assertSame(
            [],
            $ahead->reserve(1),
            'A relay whose own clock has passed the lease must still be refused: the store has not.'
        );
    }

    /**
     * The other direction, and the one that is easy to miss.
     *
     * A lease boundary is a comparison between two instants, so *both* of them
     * have to come from the store's clock — moving only the cutoff there leaves
     * the boundary spanning two clocks, which is no boundary at all. A relay
     * whose clock runs behind stamps its own claim into the past, and every
     * other relay then reads that claim as lapsed and takes an entry that is
     * being delivered right now.
     *
     * This case exists because the counter-check found it: with the comparison
     * server-side but the claim still stamped by the caller, the case above
     * passes and this one does not.
     */
    public function test_aRelayWhoseClockRunsBehindCannotMakeItsOwnClaimLookLapsed(): void
    {
        $lease = 60;

        [$onTime, $behind] = $this->getRelaysWithSkewedClocks($lease, -($lease + 1));

        $behind->enqueue([new AnotherDummyEvent(EntityIdentifier::fromString('OutboxLaggingRelay'), 1)]);

        $this->assertCount(1, $behind->reserve(1), 'Precondition: the lagging relay holds the claim.');

        $this->assertSame(
            [],
            $onTime->reserve(1),
            'The claim is live by the store\'s clock, whatever the relay that took it believed the time was.'
        );
    }

    public function test_reserveHonoursItsLimit(): void
    {
        $outbox = $this->getOutbox();
        $aggregateId = EntityIdentifier::fromString('OutboxLimited');

        $outbox->enqueue([
            new AnotherDummyEvent($aggregateId, 1),
            new AnotherDummyEvent($aggregateId, 2),
            new AnotherDummyEvent($aggregateId, 3),
        ]);

        $this->assertCount(2, $outbox->reserve(2));
    }

    public function test_reservingAnEmptyOutboxYieldsNothing(): void
    {
        $this->assertSame([], $this->getOutbox()->reserve(10));
    }

    public function test_aDeliveredEntryIsGone(): void
    {
        $outbox = $this->getOutbox();
        $outbox->enqueue([new AnotherDummyEvent(EntityIdentifier::fromString('OutboxDelivered'), 1)]);

        $entry = $outbox->reserve(1)[0];
        $outbox->markDelivered($entry);

        $this->assertSame(0, $outbox->countPending());
        $this->assertSame([], $outbox->reserve(10), 'A delivered entry must not come back.');
    }

    /**
     * A failed entry has to become claimable again — otherwise a consumer
     * being briefly unavailable silently drops the message — and it has to
     * carry the failure forward, or a poisoned entry is retried forever.
     */
    public function test_aFailedEntryBecomesClaimableAgainWithItsAttemptRecorded(): void
    {
        $outbox = $this->getOutbox();
        $outbox->enqueue([new AnotherDummyEvent(EntityIdentifier::fromString('OutboxRetried'), 1)]);

        $entry = $outbox->reserve(1)[0];
        $outbox->markFailed($entry);

        $this->assertSame(1, $outbox->countPending());

        $again = $outbox->reserve(1);
        $this->assertCount(1, $again);
        $this->assertSame(1, $again[0]->getAttempts(), 'The attempt count is what lets a relay give up.');

        $outbox->markFailed($again[0]);
        $this->assertSame(2, $outbox->reserve(1)[0]->getAttempts());
    }

    /**
     * A poisoned entry has to be able to leave.
     *
     * `markFailed()` puts an entry straight back into the pending set with its
     * attempt count raised, which is right for a consumer that was briefly
     * down and wrong for one that will never accept the message. Before this,
     * the relay called `markFailed()` for an entry it had already given up on
     * — so the entry was re-claimed on every pass forever, burning a slot of
     * every batch, and `countPending()` never drained. That made the one
     * documented monitoring signal unable to tell a stopped relay from a
     * single message nobody will take.
     */
    public function test_anAbandonedEntryIsNeverClaimedAgain(): void
    {
        $outbox = $this->getOutbox();
        $outbox->enqueue([new AnotherDummyEvent(EntityIdentifier::fromString('OutboxPoisoned'), 1)]);

        $entry = $outbox->reserve(1)[0];
        $outbox->markAbandoned($entry);

        $this->assertSame([], $outbox->reserve(10), 'An abandoned entry must not be handed out again.');
        $this->assertSame(0, $outbox->countPending(), 'And must not keep countPending() from draining.');
    }

    /**
     * Two numbers because they answer two different questions: pending that
     * only grows means the relay has stopped, abandoned that grows means a
     * consumer is rejecting something. One number cannot say both.
     */
    public function test_anAbandonedEntryIsCountedSeparately(): void
    {
        $outbox = $this->getOutbox();
        $aggregateId = EntityIdentifier::fromString('OutboxAbandonedCount');

        $this->assertSame(0, $outbox->countAbandoned());

        $outbox->enqueue([
            new AnotherDummyEvent($aggregateId, 1),
            new AnotherDummyEvent($aggregateId, 2),
        ]);

        $reserved = $outbox->reserve(10);
        $outbox->markAbandoned($reserved[0]);

        $this->assertSame(1, $outbox->countAbandoned());
        $this->assertSame(1, $outbox->countPending(), 'The other entry is untouched.');

        $outbox->markDelivered($reserved[1]);

        $this->assertSame(1, $outbox->countAbandoned(), 'Delivering one does not clear the other.');
        $this->assertSame(0, $outbox->countPending());
    }

    /**
     * The dead letter has to be readable, or it is a hole rather than a
     * destination — an operator alerting on countAbandoned() then has nothing
     * to look at.
     */
    public function test_anAbandonedEntryCanStillBeInspected(): void
    {
        $outbox = $this->getOutbox();
        $outbox->enqueue([new AnotherDummyEvent(EntityIdentifier::fromString('OutboxInspectable'), 4)]);

        $outbox->markAbandoned($outbox->reserve(1)[0]);

        $abandoned = $outbox->retrieveAbandoned(10);

        $this->assertCount(1, $abandoned);
        $this->assertSame('OutboxInspectable', (string) $abandoned[0]->getEvent()->getAggregateId());
        $this->assertSame(4, $abandoned[0]->getEvent()->getVersion()->toInt());
    }

    /**
     * The dead letter is unbounded by construction — nothing drains it but an
     * operator — so reading it has to be pageable for the same reason
     * reserve() is.
     */
    public function test_retrieveAbandonedHonoursItsLimit(): void
    {
        $outbox = $this->getOutbox();
        $aggregateId = EntityIdentifier::fromString('OutboxAbandonedPage');

        $outbox->enqueue([
            new AnotherDummyEvent($aggregateId, 1),
            new AnotherDummyEvent($aggregateId, 2),
            new AnotherDummyEvent($aggregateId, 3),
        ]);

        foreach ($outbox->reserve(10) as $entry) {
            $outbox->markAbandoned($entry);
        }

        $this->assertSame(3, $outbox->countAbandoned());
        $this->assertCount(2, $outbox->retrieveAbandoned(2));
        $this->assertSame([], $outbox->retrieveAbandoned(0), 'A limit of zero asks for nothing and must cost nothing.');
    }

    /**
     * Same reason `markFailed()` has to tolerate it: a relay that dies between
     * acting and marking retries the whole step on restart.
     */
    public function test_abandoningAnEntryThatIsNoLongerThereIsHarmless(): void
    {
        $outbox = $this->getOutbox();
        $outbox->enqueue([new AnotherDummyEvent(EntityIdentifier::fromString('OutboxAbandonTwice'), 1)]);

        $entry = $outbox->reserve(1)[0];
        $outbox->markDelivered($entry);
        $outbox->markAbandoned($entry);

        $this->assertSame(0, $outbox->countPending());
        $this->assertSame(0, $outbox->countAbandoned(), 'A delivered entry must not be resurrected as a dead letter.');
    }

    public function test_markingAnEntryThatIsNoLongerThereIsHarmless(): void
    {
        $outbox = $this->getOutbox();
        $outbox->enqueue([new AnotherDummyEvent(EntityIdentifier::fromString('OutboxVanished'), 1)]);

        $entry = $outbox->reserve(1)[0];
        $outbox->markDelivered($entry);

        // A relay that dies between delivering and marking will retry the
        // whole step on restart, so this has to be a no-op rather than a
        // failure.
        $outbox->markFailed(new OutboxEntry($entry->getId(), $entry->getEvent(), $entry->getAttempts()));

        $this->assertSame(0, $outbox->countPending());
    }
}
