<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Storage;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\DefaultEventEntryFactory;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventMetadata;
use DomainFlow\EventSourcing\Event\EventPersistenceRecord;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\GlobalEventPage;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Facade\EventSourcingFacade;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Repository\AggregateRepository;
use DomainFlow\EventSourcing\Storage\InMemoryEventStorage;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use DomainFlow\EventSourcing\Upcaster\ReflectionEventFactory;
use DomainFlow\EventSourcingCore\Provider\Unit\AbstractEventStorageTestCase;
use DomainFlow\EventSourcingCore\Provider\Unit\ExplodingEventEntryFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;

#[CoversClass(GlobalEventPage::class)]
#[CoversClass(EventId::class)]
#[CoversClass(EventVersion::class)]
#[CoversClass(OccurredOn::class)]
#[CoversClass(InMemoryEventStorage::class)]
#[CoversClass(EntityIdentifier::class)]
#[CoversClass(DefaultEventEntryFactory::class)]
#[CoversClass(EventPersistenceRecord::class)]
#[CoversClass(ReflectionEventFactory::class)]
#[CoversClass(EventEntry::class)]
#[UsesClass(EventMetadata::class)]
#[UsesTrait(HasEventMetadata::class)]
#[UsesClass(EventSourcingFacade::class)]
#[UsesClass(AggregateRepository::class)]
final class InMemoryEventStorageTest extends AbstractEventStorageTestCase
{
    protected function getStorage(): EventStorageInterface
    {
        return new InMemoryEventStorage();
    }

    protected function getStorageWhoseWritesFailWithoutConflict(): EventStorageInterface
    {
        // No database here to reject anything, so the nearest equivalent is a
        // failure on the write path that has nothing to do with versions.
        return new InMemoryEventStorage(new ExplodingEventEntryFactory());
    }

    protected function getStorageWithFactory(): EventStorageInterface
    {
        return new InMemoryEventStorage(
            null,
            new ReflectionEventFactory(),
        );
    }

    protected function tearDown(): void
    {
        // InMemoryEventStorage's constructor sets EventEntry's static factory as a
        // side effect when given a non-null EventFactoryInterface; reset it so this
        // test's state doesn't leak into unrelated tests running later in the process.
    }

    /**
     * This reference implementation has no outbox integration at all, so it
     * never puts a second delivery path in play.
     *
     * @return EventStorageInterface|null
     */
    protected function getStorageDeliveringThroughOutbox(): ?EventStorageInterface
    {
        return null;
    }
}
