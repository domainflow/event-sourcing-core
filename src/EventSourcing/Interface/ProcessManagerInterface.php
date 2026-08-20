<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

use DomainFlow\EventSourcing\ProcessManager\ProcessManagerState;

interface ProcessManagerInterface
{
    /**
     * Start the process manager with initial event and state.
     *
     * @param DomainEventInterface $event
     * @return void
     */
    public function start(DomainEventInterface $event): void;

    /**
     * Handle events and continue the process.
     *
     * @param DomainEventInterface $event
     * @return void
     */
    public function handle(DomainEventInterface $event): void;

    /**
     * Check if the process is complete.
     *
     * @return bool
     */
    public function isComplete(): bool;

    /**
     * Get the current state of the process manager.
     *
     * @return ProcessManagerState
     */
    public function getState(): ProcessManagerState;
}
