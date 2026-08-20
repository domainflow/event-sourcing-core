<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

use DomainFlow\EventSourcing\Outbox\OutboxEntry;

/**
 * Pending deliveries, written with the events they belong to.
 *
 * The point of an outbox is that enqueuing is part of the same write as the
 * events themselves. `EventSourcingFacade::persist()` stores and then
 * dispatches as two uncoordinated steps, so a process dying in between leaves
 * events committed and never delivered with nothing to recover from. An entry
 * written inside the same transaction cannot be lost that way: either both
 * landed or neither did.
 *
 * That is why every adapter's event storage takes an outbox as a collaborator
 * rather than the two being composed from outside. "Inside the same
 * transaction" is not something a decorator can arrange across MySQL, MongoDB
 * and Redis — each has its own notion of one, and Redis has none at all
 * outside a Lua script.
 *
 * Delivery is at-least-once. A relay that has delivered an entry and dies
 * before marking it will deliver it again, and no arrangement short of a
 * distributed transaction with the consumer changes that.
 *
 * **Delivery is unordered, and that is a decision rather than an omission
 *.** Entries are claimed oldest-first, but nothing keeps one
 * aggregate's entries together: two relays running in parallel split a stream
 * between them, and a single relay that fails on one entry and succeeds on the
 * next delivers them the other way round. A consumer must tolerate that.
 *
 * Ordering was considered and deliberately not promised, because the promise
 * could not be kept. Guaranteeing per-aggregate order means an entry that
 * fails has to block its aggregate until it succeeds — otherwise the entry
 * behind it overtakes it — and that turns one undeliverable message into a
 * stalled stream. The escape from *that* is markAbandoned() below, at which
 * point the order is broken anyway, silently, at the worst possible moment. A
 * guarantee with a hole in it exactly where things are going wrong is worse
 * than no guarantee, because it is the one nobody re-reads under pressure.
 *
 * What a consumer needs instead is already here. Every event carries its
 * aggregate id and its version, which is a total order per aggregate — a
 * consumer that buffers or rejects out-of-order events has everything it needs
 * to do so. And an in-process projection wanting the store's own order should
 * read the store: `Projector\CatchUpReader` over
 * `EventStorageInterface::retrieveEventsFromPosition()` is ordered, resumable,
 * and is what that path is for. The outbox exists for delivery *out* of the
 * process, where the receiving side has its own ordering needs regardless.
 */
interface OutboxStorageInterface
{
    /**
     * Records a delivery for each event.
     *
     * Called from inside the event storage's own write, so implementations
     * must not open a transaction of their own.
     *
     * @param array<DomainEventInterface> $events
     * @return void
     */
    public function enqueue(array $events): void;

    /**
     * Claims up to $limit pending entries for this relay.
     *
     * Claiming has to be atomic against other relays: two workers must never
     * receive the same entry from a single call, or at-least-once turns into
     * a guaranteed duplicate on every cycle.
     *
     * An implementation that expires claims after a lease — every one here does,
     * because otherwise a relay dying between claiming and marking strands its
     * entries for good — **must measure that lease with the store's clock, not
     * the calling relay's**. Both halves of it: the instant a claim is
     * stamped and the instant it is compared against. A fleet of relays is the
     * normal deployment and is the reason the lease exists at all, so the lease
     * is the one quantity every member of that fleet has to agree on. Measured
     * per relay, two hosts whose clocks are a minute apart — unremarkable for
     * containers without strict NTP — disagree about when a claim lapsed: the
     * fast one takes entries the slow one is still delivering, so at-least-once
     * becomes a duplicate on every cycle, and nothing reports it because
     * nothing is wrong from either relay's point of view.
     *
     * @param int $limit
     * @return list<OutboxEntry>
     */
    public function reserve(int $limit): array;

    /**
     * The entry was delivered and is done with.
     *
     * @param OutboxEntry $entry
     * @return void
     */
    public function markDelivered(OutboxEntry $entry): void;

    /**
     * Delivery failed; the entry goes back into the pending set with its
     * attempt count raised.
     *
     * For a consumer that was briefly unavailable. For one that will never
     * accept the message, use markAbandoned().
     *
     * @param OutboxEntry $entry
     * @return void
     */
    public function markFailed(OutboxEntry $entry): void;

    /**
     * The relay has given up on this entry: it leaves the pending set for
     * good and goes somewhere an operator can look at it.
     *
     * Distinct from markFailed() because the two mean opposite things to the
     * next pass. The relay used to call markFailed() for an entry past its
     * attempt limit, which raised the attempt count and put it *back* into the
     * pending set — so it was claimed again on every pass forever, burning a
     * slot of every batch, and countPending() could never drain.
     *
     * Must be harmless for an entry that is no longer there, for the same
     * reason markFailed() must be: a relay dying between acting and marking
     * retries the whole step on restart. In particular an already-delivered
     * entry must not be resurrected as a dead letter.
     *
     * @param OutboxEntry $entry
     * @return void
     */
    public function markAbandoned(OutboxEntry $entry): void;

    /**
     * The dead letters, oldest first, for an operator who has been alerted by
     * countAbandoned() and now has to look at what is stuck.
     *
     * A dead letter nobody can read is a hole rather than a destination.
     *
     * @param int $limit
     * @return list<OutboxEntry>
     */
    public function retrieveAbandoned(int $limit): array;

    /**
     * How many entries are still waiting to be delivered. For monitoring: a
     * number that only grows is the signal that a relay has stopped.
     *
     * @return int
     */
    public function countPending(): int;

    /**
     * How many entries the relay has given up on.
     *
     * The second monitoring signal, and it exists because one number cannot
     * answer both questions: pending that only grows means the relay has
     * stopped, abandoned that grows means a consumer is rejecting something.
     * Before markAbandoned() existed, a single poisoned message made the first
     * number look exactly like the second condition.
     *
     * @return int
     */
    public function countAbandoned(): int;
}
