<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\ProcessManager;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use DomainFlow\EventSourcing\Exception\ProcessManagerConcurrencyException;
use DomainFlow\EventSourcing\Interface\ProcessManagerStorageInterface;
use Throwable;

/**
 * Fires saga timeouts: find what is overdue, run each saga's timeout hook,
 * store the result.
 *
 * The counterpart to `OutboxRelay`, and scheduled the same way — a worker a
 * consumer runs on a timer. It is what makes `setTimeout()` mean anything: a
 * timeout exists precisely for the case where no event is arriving, so nothing
 * else in this library is ever going to look at the process again.
 *
 * Firing is at-most-once per due timeout, by the version check on `store()`:
 * two workers finding the same process both run the hook, but only one write
 * lands, so only one of them advances the saga. A hook is therefore allowed to
 * mutate state, but must not be the only place a side effect happens — enqueue
 * it and let the outbox own the delivery, as with any other saga decision.
 */
final class ProcessManagerTimeoutRunner
{
    /** @var Closure(ProcessManagerState): ?class-string<AbstractProcessManager> */
    private readonly Closure $resolveProcessManagerClass;

    /**
     * @param ProcessManagerStorageInterface $storage
     * @param callable(ProcessManagerState): ?class-string<AbstractProcessManager> $resolveProcessManagerClass
     *        Which saga a found state belongs to, or null to leave it alone.
     *
     *        The caller has to say, because the store cannot: state is keyed by
     *        process id and carries no type discriminator, so a runner that
     *        guessed would sooner or later run one saga's hook against
     *        another's state. A consumer with a single saga returns its class
     *        and is done; one with several dispatches on whatever it already
     *        uses to keep process ids apart.
     * @param int $batchSize How many overdue processes to take per pass.
     */
    public function __construct(
        private readonly ProcessManagerStorageInterface $storage,
        callable $resolveProcessManagerClass,
        private readonly int $batchSize = 100
    ) {
        $this->resolveProcessManagerClass = Closure::fromCallable($resolveProcessManagerClass);
    }

    /**
     * One pass: take the overdue processes, fire each one, record the outcome.
     *
     * @param DateTimeImmutable|null $asOf Cutoff, defaulting to now. Pass it
     *        explicitly to keep a test's clock still.
     * @return ProcessManagerTimeoutResult
     */
    public function run(
        ?DateTimeImmutable $asOf = null
    ): ProcessManagerTimeoutResult {
        $fired = 0;
        $contended = 0;
        $skipped = 0;
        $failed = 0;

        $cutoff = $asOf ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($this->storage->findTimedOut($cutoff, $this->batchSize) as $state) {
            $processManagerClass = ($this->resolveProcessManagerClass)($state);

            if ($processManagerClass === null) {
                $skipped++;

                continue;
            }

            $processManager = $processManagerClass::fromState($state);

            $state->clearTimeout();

            try {
                $processManager->onTimeout();
            } catch (Throwable) {
                $failed++;

                continue;
            }

            try {
                $this->storage->store($processManager->getState());
                $fired++;
            } catch (ProcessManagerConcurrencyException) {
                $contended++;
            }
        }

        return new ProcessManagerTimeoutResult($fired, $contended, $skipped, $failed);
    }
}
