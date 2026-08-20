<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Concurrency;

use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\GlobalEventPage;
use DomainFlow\EventSourcing\Exception\ConcurrencyException;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\OutboxBackedStorageInterface;

final readonly class ConcurrencyCheckingStorage implements EventStorageInterface, OutboxBackedStorageInterface
{
    public function __construct(
        private EventStorageInterface $inner,
        private ConcurrencyCheckStrategyInterface $strategy
    ) {
    }

    /**
     * Forwarded, not answered.
     *
     * A decorator that swallowed this would leave `EventSourcingFacade`'s
     * double-delivery guard reading a wrapper that has no outbox instead of the
     * storage that does — a check that passes silently, which is worse than no
     * check, because the configuration then looks verified.
     *
     * An inner storage that says nothing answers `false`, matching the rule
     * that silence is never read as a refusal.
     *
     * @return bool
     */
    public function deliversThroughOutbox(): bool
    {
        return $this->inner instanceof OutboxBackedStorageInterface && $this->inner->deliversThroughOutbox();
    }

    /**
     *  Stores events in the storage.
     *
     * @param DomainEventInterface[] $events
     * @throws ConcurrencyException
     * @return void
     */
    public function storeEvents(
        array $events
    ): void {
        $this->strategy->assertNoConflict($events, $this->inner);
        $this->inner->storeEvents($events);
    }

    /**
     * Retrieves events from the storage.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return array|DomainEventInterface[]
     */
    public function retrieveEvents(
        EntityIdentifierInterface $aggregateId
    ): array {
        return $this->inner->retrieveEvents($aggregateId);
    }

    /**
     * Retrieves an aggregate's events newer than a given version.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @param EventVersion $afterVersion
     * @return array|DomainEventInterface[]
     */
    public function retrieveEventsFromVersion(
        EntityIdentifierInterface $aggregateId,
        EventVersion $afterVersion
    ): array {
        return $this->inner->retrieveEventsFromVersion($aggregateId, $afterVersion);
    }

    /**
     * Deletes events from the storage.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return void
     */
    public function deleteEvents(
        EntityIdentifierInterface $aggregateId
    ): void {
        $this->inner->deleteEvents($aggregateId);
    }

    /**
     * Retrieves all events from the storage.
     *
     * @return iterable<DomainEventInterface>
     */
    public function retrieveAllEvents(): iterable
    {
        return $this->inner->retrieveAllEvents();
    }

    /**
     * Reads the global stream from a position.
     *
     * @param string|null $afterPosition
     * @param int $limit
     * @return GlobalEventPage
     */
    public function retrieveEventsFromPosition(
        ?string $afterPosition,
        int $limit
    ): GlobalEventPage {
        return $this->inner->retrieveEventsFromPosition($afterPosition, $limit);
    }

    /**
     * Retrieves paginated events from the storage.
     *
     * @deprecated See EventStorageInterface::retrievePaginatedEvents().
     * @param int|null $offset
     * @param int|null $limit
     * @return array|DomainEventInterface[]
     */
    public function retrievePaginatedEvents(
        ?int $offset,
        ?int $limit
    ): array {
        return $this->inner->retrievePaginatedEvents($offset, $limit);
    }

    /**
     * Retrieves the current max version of an aggregate.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return EventVersion
     */
    public function getCurrentMaxVersion(
        EntityIdentifierInterface $aggregateId
    ): EventVersion {
        return $this->inner->getCurrentMaxVersion($aggregateId);
    }
}
