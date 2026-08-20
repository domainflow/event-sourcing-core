<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\ProcessManager;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventSubscriberInterface;
use DomainFlow\EventSourcing\Interface\ProcessManagerInterface;
use LogicException;

/**
 * Base class for event-driven process managers (sagas).
 *
 * A single long-lived instance can be registered directly with an
 * EventDispatcher (via EventSubscriberInterface) for in-process,
 * non-persisted sagas - state lives in the object for as long as the
 * process runs, exactly like the pattern this replaces. For sagas that
 * need their state reloaded/persisted per event (e.g. a queue worker
 * that gets a fresh PHP process per message), use ProcessManagerRepository
 * instead of registering with the dispatcher directly.
 */
abstract class AbstractProcessManager implements ProcessManagerInterface, EventSubscriberInterface
{
    private ?ProcessManagerState $processManagerState = null;

    /**
     * Create a new, empty instance of the concrete process manager.
     * Used internally by fromState() so this abstract base never
     * instantiates itself via `new static()`.
     *
     * @return static
     */
    abstract protected static function newInstance(): static;

    /**
     * Extract the correlation ID identifying which process instance a
     * given event belongs to (e.g. an order ID for an order fulfillment saga).
     *
     * @param DomainEventInterface $event
     * @return EntityIdentifierInterface
     */
    abstract public static function correlationId(DomainEventInterface $event): EntityIdentifierInterface;

    /**
     * Build the initial business data payload when the process starts.
     *
     * @param DomainEventInterface $event
     * @return array<string, mixed>
     */
    abstract protected function createInitialData(DomainEventInterface $event): array;

    /**
     * React to an event once the process has already started. Call
     * markCompleted()/markFailed() to end the process, or mutate the
     * state's data to keep waiting for further events.
     *
     * @param DomainEventInterface $event
     * @return void
     */
    abstract protected function onEvent(DomainEventInterface $event): void;

    /**
     * Reconstitute a process manager from previously-persisted state
     * (or a fresh instance if $state is null - i.e. a process that
     * hasn't started yet).
     *
     * @param ProcessManagerState|null $state
     * @return static
     */
    public static function fromState(
        ?ProcessManagerState $state
    ): static {
        $instance = static::newInstance();
        $instance->processManagerState = $state;

        return $instance;
    }

    public function start(
        DomainEventInterface $event
    ): void {
        if ($this->processManagerState !== null) {
            throw new LogicException(sprintf('%s has already been started.', static::class));
        }

        $this->processManagerState = new ProcessManagerState(
            static::correlationId($event),
            ProcessManagerStateEnum::PROCESSING
        );
        $this->processManagerState->setData($this->createInitialData($event));
    }

    /**
     * Route an event to this process — if it is this process's event, and if
     * this process is still running.
     *
     * Neither used to be checked past `start()`. A saga registered directly
     * with an `EventDispatcher` is a single long-lived object handed *every*
     * event of the types it subscribed to, so an event of order B reached the
     * instance watching order A and completed it; the state that was then
     * written named A while the decision had been made about B. And a
     * redelivered event — the ordinary case on an at-least-once transport —
     * ran `onEvent()` on a process that had already ended, taking its
     * compensating action a second time.
     *
     * Both are ignored rather than refused. An event this instance is not the
     * addressee of is not an error anywhere: the dispatcher fans out by event
     * type and has no idea which instance an event correlates to, and a
     * redelivery is what the transport promises. Throwing would turn normal
     * traffic into failures.
     *
     * @param DomainEventInterface $event
     * @return void
     */
    public function handle(
        DomainEventInterface $event
    ): void {
        if ($this->processManagerState === null) {
            $this->start($event);

            return;
        }

        if (!$this->isCorrelatedWith($event, $this->processManagerState) || $this->isComplete()) {
            return;
        }

        $this->onEvent($event);
    }

    /**
     * Whether an event belongs to the process instance this object holds.
     *
     * Compared as strings rather than through `EntityIdentifierInterface::equals()`,
     * which is class-strict. A state comes back from storage carrying this
     * package's `EntityIdentifier`, while `correlationId()` hands back
     * whatever identifier class the consumer's domain uses — comparing the
     * objects would call a saga's own events foreign and silently stop
     * handling them. Storage keys the state by that string too, so it is the
     * identity the rest of this path already works in.
     *
     * @param DomainEventInterface $event
     * @param ProcessManagerState $state Passed in rather than read off the
     *        object: the caller has already established there is one, and a
     *        second null check here would be a branch nothing can reach.
     * @return bool
     */
    private function isCorrelatedWith(
        DomainEventInterface $event,
        ProcessManagerState $state
    ): bool {
        return (string) static::correlationId($event) === (string) $state->getProcessId();
    }

    public function isComplete(): bool
    {
        if ($this->processManagerState === null) {
            return false;
        }

        $status = $this->processManagerState->getStatus();

        return $status->isCompleted() || $status->isFailed();
    }

    public function getState(): ProcessManagerState
    {
        if ($this->processManagerState === null) {
            throw new LogicException(sprintf('%s has not been started yet.', static::class));
        }

        return $this->processManagerState;
    }

    /**
     * Whether this process's configured timeout (if any) has elapsed.
     *
     * @param DateTimeImmutable|null $now Defaults to the current time; pass explicitly to keep this testable.
     * @return bool
     */
    public function hasTimedOut(
        ?DateTimeImmutable $now = null
    ): bool {
        if ($this->processManagerState === null) {
            return false;
        }

        $timeout = $this->processManagerState->getTimeout();
        if ($timeout === null) {
            return false;
        }

        return ($now ?? new DateTimeImmutable()) >= $timeout;
    }

    /**
     * Called when this process's timeout has come due.
     *
     * A no-op by default, so a saga that has no use for timeouts does not have
     * to say so — and one that never calls `setTimeout()` is never found by
     * `ProcessManagerTimeoutRunner` in the first place.
     *
     * The timeout that triggered this has already been dropped by the time the
     * hook runs, so a saga that wants another attempt says so by calling
     * `setTimeout()` again here. Whatever the hook leaves behind is stored
     * under the same version check as any other saga write, which means one
     * worker advances the process even when several found it.
     *
     * @return void
     */
    public function onTimeout(): void
    {
    }

    protected function markCompleted(): void
    {
        $this->getState()->setStatus(ProcessManagerStateEnum::COMPLETED);
    }

    protected function markFailed(): void
    {
        $this->getState()->setStatus(ProcessManagerStateEnum::FAILED);
    }

    protected function setTimeout(
        DateTimeImmutable $timeout
    ): void {
        $this->getState()->setTimeout($timeout);
    }
}
