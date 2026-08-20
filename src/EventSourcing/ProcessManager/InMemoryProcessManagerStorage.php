<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\ProcessManager;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Exception\ProcessManagerConcurrencyException;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\ProcessManagerStorageInterface;

/**
 * Reference implementation, and a real store rather than a shared object cache:
 * `retrieve()` hands out a copy.
 *
 * Returning the stored instance would make two workers loading the same process
 * share one object, so a stale write could not even be expressed — mutating one
 * would mutate the other and the version comparison would always agree with
 * itself. That is not a testing detail; it is the difference between modelling
 * the contract and pretending to.
 */
class InMemoryProcessManagerStorage implements ProcessManagerStorageInterface
{
    /** @var array<string, array{version: int, state: ProcessManagerState}> */
    private array $states = [];

    /**
     * @param ProcessManagerState $state
     * @throws ProcessManagerConcurrencyException
     * @return void
     */
    public function store(
        ProcessManagerState $state
    ): void {
        $processId = (string) $state->getProcessId();
        $stored = $this->states[$processId]['version'] ?? 0;

        if ($stored !== $state->getVersion()) {
            throw ProcessManagerConcurrencyException::versionMoved(
                $state->getProcessId(),
                $state->getVersion(),
                $stored
            );
        }

        $next = $stored + 1;

        // A copy goes into the store, so a caller mutating its own object
        // afterwards does not silently change what was written.
        $stored = clone $state;
        $stored->markPersisted($next);

        $this->states[$processId] = ['version' => $next, 'state' => $stored];
        $state->markPersisted($next);
    }

    public function retrieve(
        EntityIdentifierInterface $processId
    ): ?ProcessManagerState {
        $state = $this->states[(string) $processId]['state'] ?? null;

        return $state === null ? null : clone $state;
    }

    /**
     * @param DateTimeImmutable $asOf
     * @param int $limit
     * @return list<ProcessManagerState>
     */
    public function findTimedOut(
        DateTimeImmutable $asOf,
        int $limit
    ): array {
        $due = [];

        foreach ($this->states as $entry) {
            $timeout = $entry['state']->getTimeout();

            if ($timeout === null || $timeout > $asOf) {
                continue;
            }

            if ($this->hasFinished($entry['state'])) {
                continue;
            }

            $due[] = clone $entry['state'];
        }

        // Oldest first, so a limited page cannot starve the process that has
        // been waiting longest.
        usort($due, static fn (ProcessManagerState $a, ProcessManagerState $b): int => $a->getTimeout() <=> $b->getTimeout());

        return array_slice($due, 0, max(0, $limit));
    }

    private function hasFinished(
        ProcessManagerState $state
    ): bool {
        $status = $state->getStatus();

        return $status->isCompleted() || $status->isFailed();
    }

    public function delete(
        EntityIdentifierInterface $processId
    ): void {
        unset($this->states[(string) $processId]);
    }
}
