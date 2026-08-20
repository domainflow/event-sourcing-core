<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Outbox;

use DomainFlow\EventSourcing\Interface\EventDispatcherInterface;
use DomainFlow\EventSourcing\Interface\OutboxStorageInterface;
use Throwable;

/**
 * Drains the outbox: claim entries, deliver them, mark what happened.
 *
 */
final readonly class OutboxRelay
{
    /**
     * @param OutboxStorageInterface $outbox
     * @param EventDispatcherInterface $dispatcher
     * @param int $batchSize How many entries to claim per pass.
     * @param int $maxAttempts After this many failures the entry is
     *        abandoned — it leaves the pending set for good and shows up in
     *        countAbandoned() instead, where an operator can alert on it and
     *        read it back. Zero means never give up, which keeps a poisoned
     *        entry in the batch forever and is only right when every consumer
     *        is guaranteed to come back.
     */
    public function __construct(
        private OutboxStorageInterface $outbox,
        private EventDispatcherInterface $dispatcher,
        private int $batchSize = 100,
        private int $maxAttempts = 10
    ) {
    }

    /**
     * One pass: claim a batch, try to deliver each entry, record the outcome.
     *
     * A failing entry does not stop the rest, for the same reason a failing
     * subscriber does not stop the others — one poisoned message must not hold
     * up every other consumer's traffic.
     *
     * @return OutboxRelayResult
     */
    public function run(): OutboxRelayResult
    {
        $delivered = 0;
        $failed = 0;
        $abandoned = 0;

        foreach ($this->outbox->reserve($this->batchSize) as $entry) {
            if ($this->hasExhaustedItsAttempts($entry)) {
                $this->outbox->markAbandoned($entry);
                $abandoned++;

                continue;
            }

            try {
                $this->dispatcher->dispatch($entry->getEvent());
                $this->outbox->markDelivered($entry);
                $delivered++;
            } catch (Throwable) {
                // The reason is the dispatcher's to report; the outbox only
                // needs to know this one is still owed.
                $this->outbox->markFailed($entry);
                $failed++;
            }
        }

        return new OutboxRelayResult($delivered, $failed, $abandoned);
    }

    private function hasExhaustedItsAttempts(
        OutboxEntry $entry
    ): bool {
        return $this->maxAttempts > 0 && $entry->getAttempts() >= $this->maxAttempts;
    }
}
