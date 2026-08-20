<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Concurrency;

use DomainFlow\EventSourcing\Exception\ConcurrencyException;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;

interface ConcurrencyCheckStrategyInterface
{
    /**
     * Assert that the events do not conflict with the current state of the aggregate.
     *
     * @param DomainEventInterface[] $events
     * @param EventStorageInterface $inner
     *
     * @throws ConcurrencyException
     */
    public function assertNoConflict(array $events, EventStorageInterface $inner): void;
}
