<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

use DomainFlow\EventSourcing\Event\EventPersistenceRecord;

interface EventEntryFactoryInterface
{
    /**
     * Create a persistence record from a domain event.
     *
     * @param DomainEventInterface $event
     * @return EventPersistenceRecord
     */
    public function createFromDomainEvent(DomainEventInterface $event): EventPersistenceRecord;

    /**
     * Create a domain event from a persistence record.
     *
     * @param EventPersistenceRecord $record
     * @return DomainEventInterface
     */
    public function recordToDomainEvent(EventPersistenceRecord $record): DomainEventInterface;
}
