<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Unit;

use DomainFlow\EventSourcing\Event\EventPersistenceRecord;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventEntryFactoryInterface;
use RuntimeException;

/**
 * Fails while turning an event into a record.
 *
 * The in-memory adapter has no database to reject a write, so this is its
 * nearest equivalent of an infrastructure failure: something goes wrong on the
 * write path for a reason that has nothing to do with versions. The point of
 * the contract case it serves is that such a failure must not be reported as a
 * concurrency conflict.
 */
final class ExplodingEventEntryFactory implements EventEntryFactoryInterface
{
    public function createFromDomainEvent(
        DomainEventInterface $event
    ): EventPersistenceRecord {
        throw new RuntimeException('The entry factory could not build a record.');
    }

    public function recordToDomainEvent(
        EventPersistenceRecord $record
    ): DomainEventInterface {
        throw new RuntimeException('The entry factory could not rebuild an event.');
    }
}
