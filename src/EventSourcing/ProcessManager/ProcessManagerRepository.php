<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\ProcessManager;

use DomainFlow\EventSourcing\Exception\ProcessManagerConcurrencyException;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\ProcessManagerStorageInterface;
use RuntimeException;

/**
 * Loads a process manager's persisted state (if any), routes an event
 * through it, and persists the resulting state - the process-manager
 * equivalent of AggregateRepository, for consumers that need state
 * reloaded per event rather than kept in a long-lived object.
 *
 * The write is conditional on the version the state was loaded at, so two
 * workers handling events for the same process do not overwrite each other —
 * see ProcessManagerStorageInterface::store(). This class is built for exactly
 * the situation where that happens, which is why it mattered.
 */
final class ProcessManagerRepository
{
    /**
     * @param ProcessManagerStorageInterface $storage
     * @param bool $forgetCompletedProcesses Whether to delete a process's state
     *        once it reports COMPLETED. Off by default: a consumer may want the
     *        record for auditing, and deleting it makes a redelivered event
     *        start a fresh process instead of being ignored. On, it stops the
     *        state store growing without bound, which is what it does today.
     */
    public function __construct(
        private readonly ProcessManagerStorageInterface $storage,
        private readonly bool $forgetCompletedProcesses = false
    ) {
    }

    /**
     * @template T of AbstractProcessManager
     * @param class-string<T> $processManagerClass
     * @param DomainEventInterface $event
     * @throws ProcessManagerConcurrencyException
     * @return T
     */
    public function handle(
        string $processManagerClass,
        DomainEventInterface $event
    ): AbstractProcessManager {
        $this->ensureProcessManagerClass($processManagerClass);

        $correlationId = $processManagerClass::correlationId($event);
        $state = $this->storage->retrieve($correlationId);

        $processManager = $processManagerClass::fromState($state);

        // A finished process is read, reported and left alone. The process
        // manager already refuses to act on an event that arrives after it
        // ended, but writing the unchanged state back would still cost a
        // store and a version bump per redelivery — on a state whose whole
        // point is that it is final. This is the behaviour
        // `$forgetCompletedProcesses` documents as "recognised as done".
        if ($state !== null && $processManager->isComplete()) {
            return $processManager;
        }

        $processManager->handle($event);

        $this->storage->store($processManager->getState());

        if ($this->forgetCompletedProcesses && $processManager->getState()->getStatus() === ProcessManagerStateEnum::COMPLETED) {
            $this->storage->delete($correlationId);
        }

        return $processManager;
    }

    /**
     * Every call below this reaches into the class statically, so a wrong
     * string produces an Error about an undefined method rather than something
     * a reader can act on. AggregateRepository::ensureAggregateClass() gets the
     * equivalent case right; this one did not.
     *
     * @param string $processManagerClass
     * @return void
     */
    private function ensureProcessManagerClass(
        string $processManagerClass
    ): void {
        if (!is_subclass_of($processManagerClass, AbstractProcessManager::class)) {
            throw new RuntimeException("Class $processManagerClass is not an AbstractProcessManager.");
        }
    }
}
