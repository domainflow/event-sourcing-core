<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Crypto;

use DomainFlow\EventSourcing\Attribute\PersonalData;
use DomainFlow\EventSourcing\Crypto\EncryptingEventEntryFactory;
use DomainFlow\EventSourcing\Crypto\InMemoryPersonalDataKeyStore;
use DomainFlow\EventSourcing\Crypto\RedactedValue;
use DomainFlow\EventSourcing\Crypto\SodiumCipher;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\DefaultEventEntryFactory;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventMetadata;
use DomainFlow\EventSourcing\Event\EventPersistenceRecord;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\GlobalEventPage;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Event\SourceEvent;
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

/**
 * The whole storage contract, run through the encrypting decorator.
 *
 * The claim crypto-shredding rests on is that **no storage knows about it** —
 * every one of them takes its entry factory as an instance dependency, so
 * wrapping it is the entire integration. A claim like that is worth more than
 * a docblock, so the contract runs again with the decorator in place and has
 * to come out the same.
 */
#[CoversClass(EncryptingEventEntryFactory::class)]
#[CoversClass(InMemoryPersonalDataKeyStore::class)]
#[CoversClass(SodiumCipher::class)]
#[CoversClass(RedactedValue::class)]
#[UsesClass(PersonalData::class)]
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
#[UsesClass(SourceEvent::class)]
#[UsesTrait(HasEventMetadata::class)]
#[UsesClass(EventSourcingFacade::class)]
#[UsesClass(AggregateRepository::class)]
final class EncryptedInMemoryEventStorageTest extends AbstractEventStorageTestCase
{
    private InMemoryPersonalDataKeyStore $keys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->keys = new InMemoryPersonalDataKeyStore();
    }

    protected function getStorage(): EventStorageInterface
    {
        return new InMemoryEventStorage($this->encrypting(new DefaultEventEntryFactory(new ReflectionEventFactory())));
    }

    protected function getStorageWithFactory(): EventStorageInterface
    {
        return new InMemoryEventStorage(
            $this->encrypting(new DefaultEventEntryFactory(new ReflectionEventFactory()))
        );
    }

    protected function getStorageWhoseWritesFailWithoutConflict(): EventStorageInterface
    {
        return new InMemoryEventStorage($this->encrypting(new ExplodingEventEntryFactory()));
    }

    private function encrypting(
        \DomainFlow\EventSourcing\Interface\EventEntryFactoryInterface $inner
    ): EncryptingEventEntryFactory {
        return new EncryptingEventEntryFactory($inner, $this->keys, new SodiumCipher());
    }

    /**
     * And the end-to-end shape of it, through a real storage rather than
     * through the factory alone: write, forget, read.
     */
    public function test_an_erased_subject_is_redacted_when_the_stream_is_replayed(): void
    {
        $storage = $this->getStorage();
        $aggregateId = EntityIdentifier::fromString('order-erased');

        $event = new CustomerRegistered($aggregateId, null, 'customer-1', 'ada@example.com', 'ORD-42');
        $event->setVersion(EventVersion::fromInt(1));
        $storage->storeEvents([$event]);

        $this->keys->forget('customer-1');

        $replayed = $storage->retrieveEvents($aggregateId);

        $this->assertCount(1, $replayed);
        $this->assertInstanceOf(CustomerRegistered::class, $replayed[0]);
        $this->assertTrue(RedactedValue::isRedacted($replayed[0]->email));
        $this->assertSame('ORD-42', $replayed[0]->orderReference);
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
