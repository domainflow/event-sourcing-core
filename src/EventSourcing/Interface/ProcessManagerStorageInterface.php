<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Exception\ProcessManagerConcurrencyException;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerState;

interface ProcessManagerStorageInterface
{
    /**
     * Persist a process manager's current state, keyed by its process ID.
     *
     * Conditional on the version the state was loaded at: if the stored
     * version has moved, the write is rejected with a
     * ProcessManagerConcurrencyException rather than overwriting whatever a
     * concurrent worker wrote. An unconditional overwrite here loses a saga
     * update on every overlap, and a saga can then sit in WAITING forever.
     *
     * On success the state is told the version it now carries, so a long-lived
     * process manager can store again without reloading.
     *
     * @param ProcessManagerState $state
     * @throws ProcessManagerConcurrencyException
     * @return void
     */
    public function store(ProcessManagerState $state): void;

    /**
     * Retrieve a process manager's state by process ID, or null if none is stored.
     *
     * @param EntityIdentifierInterface $processId
     * @return ProcessManagerState|null
     */
    public function retrieve(EntityIdentifierInterface $processId): ?ProcessManagerState;

    /**
     * Processes whose timeout has come due and which are still running.
     *
     * The read side of `setTimeout()`. Without it a timeout could only be
     * noticed by someone who already held the process id — that is, by someone
     * already handling an event for that saga — which is the one case where a
     * timeout is not needed. The case it exists for is the opposite: nothing is
     * arriving, and something has to notice.
     *
     * Returned oldest-first, and that ordering is part of the contract rather
     * than an accident of the query plan: with a limit and no ordering, a store
     * that keeps answering with the same page starves everything behind it, and
     * the process waiting longest is exactly the one that must not be starved.
     *
     * COMPLETED and FAILED processes are excluded. They keep their row, and
     * their timeout with it, but there is nothing left to do to them — handing
     * one to a worker would mean handing it over again on every pass, because a
     * finished process is not going to clear anything.
     *
     * The limit is required, not defaulted: this is a worker's poll, and an
     * unbounded answer to "what is overdue" is a footgun on the one day it
     * matters.
     *
     * @param DateTimeImmutable $asOf Cutoff, inclusive — a timeout is due at
     *        the instant it names.
     * @param int $limit Upper bound on how many are returned.
     * @return list<ProcessManagerState> Oldest timeout first.
     */
    public function findTimedOut(DateTimeImmutable $asOf, int $limit): array;

    /**
     * Delete a process manager's stored state.
     *
     * @param EntityIdentifierInterface $processId
     * @return void
     */
    public function delete(EntityIdentifierInterface $processId): void;
}
