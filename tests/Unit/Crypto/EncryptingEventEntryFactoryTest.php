<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Crypto;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Attribute\DataSubjectId;
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
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventEntryFactoryInterface;
use DomainFlow\EventSourcing\Upcaster\ReflectionEventFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(EncryptingEventEntryFactory::class)]
#[CoversClass(PersonalData::class)]
#[CoversClass(InMemoryPersonalDataKeyStore::class)]
#[CoversClass(SodiumCipher::class)]
#[CoversClass(RedactedValue::class)]
#[UsesClass(DefaultEventEntryFactory::class)]
#[UsesClass(EventPersistenceRecord::class)]
#[UsesClass(EntityIdentifier::class)]
#[UsesClass(EventVersion::class)]
#[UsesClass(SourceEvent::class)]
#[UsesClass(ReflectionEventFactory::class)]
#[UsesClass(EventEntry::class)]
#[UsesClass(EventId::class)]
#[UsesClass(EventMetadata::class)]
#[UsesClass(OccurredOn::class)]
final class EncryptingEventEntryFactoryTest extends TestCase
{
    private InMemoryPersonalDataKeyStore $keys;

    protected function setUp(): void
    {
        $this->keys = new InMemoryPersonalDataKeyStore();
    }

    private function factory(): EncryptingEventEntryFactory
    {
        return new EncryptingEventEntryFactory(
            new DefaultEventEntryFactory(new ReflectionEventFactory()),
            $this->keys,
            new SodiumCipher()
        );
    }

    private function customerRegistered(
        string $customerId = 'customer-1',
        string $email = 'ada@example.com'
    ): CustomerRegistered {
        $event = new CustomerRegistered(EntityIdentifier::fromString('order-1'), null, $customerId, $email, 'ORD-42');
        $event->setVersion(EventVersion::fromInt(1));

        return $event;
    }

    private function readBack(
        EventPersistenceRecord $record
    ): DomainEventInterface {
        // Through storage-shaped data, so nothing depends on the object that
        // produced the record still being in memory.
        return $this->factory()->recordToDomainEvent(EventPersistenceRecord::fromArray($record->toArray()));
    }

    /**
     * The case the whole feature exists for. An event store is append-only and
     * the right to erasure is not, so the data has to become unreadable
     * without the event being touched: destroy the key, and the ciphertext
     * that is left says nothing.
     */
    public function test_forgetting_the_key_redacts_the_personal_field_and_nothing_else(): void
    {
        $record = $this->factory()->createFromDomainEvent($this->customerRegistered());

        $this->keys->forget('customer-1');

        $event = $this->readBack($record);

        $this->assertInstanceOf(CustomerRegistered::class, $event);
        $this->assertTrue(RedactedValue::isRedacted($event->email), 'The personal field survived erasure.');
        $this->assertSame('ORD-42', $event->orderReference, 'Erasure took a field that was never personal.');
        $this->assertSame('customer-1', $event->customerId);
    }

    public function test_the_stored_payload_does_not_contain_the_personal_value(): void
    {
        $record = $this->factory()->createFromDomainEvent($this->customerRegistered());

        $payload = $record->toArray()['payload'] ?? '';

        $this->assertIsString($payload);
        $this->assertStringNotContainsString('ada@example.com', $payload, 'The personal data was written in the clear.');
        $this->assertStringContainsString('ORD-42', $payload, 'A field that is not personal must stay readable.');
    }

    public function test_encryption_is_invisible_while_the_key_is_there(): void
    {
        $record = $this->factory()->createFromDomainEvent($this->customerRegistered());

        $event = $this->readBack($record);

        $this->assertInstanceOf(CustomerRegistered::class, $event);
        $this->assertSame('ada@example.com', $event->email);
        $this->assertSame('ORD-42', $event->orderReference);
    }

    /**
     * Erasing one subject must not reach anyone else's data — the failure here
     * would be silent and would only show up in an audit.
     */
    public function test_forgetting_one_subject_leaves_every_other_subject_readable(): void
    {
        $factory = $this->factory();
        $ada = $factory->createFromDomainEvent($this->customerRegistered('customer-1', 'ada@example.com'));
        $grace = $factory->createFromDomainEvent($this->customerRegistered('customer-2', 'grace@example.com'));

        $this->keys->forget('customer-1');

        $erased = $this->readBack($ada);
        $kept = $this->readBack($grace);

        $this->assertInstanceOf(CustomerRegistered::class, $erased);
        $this->assertInstanceOf(CustomerRegistered::class, $kept);
        $this->assertTrue(RedactedValue::isRedacted($erased->email));
        $this->assertSame('grace@example.com', $kept->email);
    }

    /**
     * Two events for one subject share a key, so erasure is one act rather
     * than a hunt through the stream.
     */
    public function test_every_event_for_a_subject_goes_dark_at_once(): void
    {
        $factory = $this->factory();
        $first = $factory->createFromDomainEvent($this->customerRegistered('customer-1', 'ada@example.com'));
        $second = $factory->createFromDomainEvent($this->customerRegistered('customer-1', 'ada@work.example.com'));

        $this->keys->forget('customer-1');

        $one = $this->readBack($first);
        $two = $this->readBack($second);

        $this->assertInstanceOf(CustomerRegistered::class, $one);
        $this->assertInstanceOf(CustomerRegistered::class, $two);
        $this->assertTrue(RedactedValue::isRedacted($one->email));
        $this->assertTrue(RedactedValue::isRedacted($two->email));
    }

    public function test_an_event_with_nothing_personal_is_passed_straight_through(): void
    {
        $event = new NoPersonalData(EntityIdentifier::fromString('order-2'), null, 'ORD-7');
        $event->setVersion(EventVersion::fromInt(1));

        $record = $this->factory()->createFromDomainEvent($event);
        $payload = $record->toArray()['payload'] ?? '';

        $this->assertIsString($payload);
        $this->assertStringContainsString('ORD-7', $payload);
        $this->assertSame([], $this->keys->knownSubjects(), 'A key was minted for an event with no personal data.');
    }

    /**
     * The subject id is what erasure is keyed on, so an event that declares
     * personal data without saying whose it is cannot be stored — there would
     * be no way to erase it later, and the failure would only be discovered
     * when someone asked.
     */
    public function test_personal_data_without_a_subject_is_refused(): void
    {
        $event = new CustomerNoted(EntityIdentifier::fromString('order-3'), null, 'lives at 12 Acacia Avenue');
        $event->setVersion(EventVersion::fromInt(1));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declares #[PersonalData] but no #[DataSubjectId]');

        $this->factory()->createFromDomainEvent($event);
    }

    /**
     * Erasure has to be able to put a marker where the value was, and only a
     * string-typed property can hold one. Refused at write time, because the
     * alternative is an event that stores fine and cannot be read back after
     * erasure — discovered years later, by the erasure itself.
     */
    public function test_a_personal_field_that_could_not_hold_a_marker_is_refused(): void
    {
        $event = new MisdeclaredPersonalData(EntityIdentifier::fromString('order-4'), null, 'customer-9', 12345);
        $event->setVersion(EventVersion::fromInt(1));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a string');

        $this->factory()->createFromDomainEvent($event);
    }

    /**
     * A key is minted once per subject and reused, or the second event for a
     * subject would be unreadable after the first erasure and readable before
     * it — two events, two answers.
     */
    public function test_a_subject_keeps_one_key(): void
    {
        $factory = $this->factory();
        $factory->createFromDomainEvent($this->customerRegistered('customer-1'));
        $key = $this->keys->keyFor('customer-1');

        $factory->createFromDomainEvent($this->customerRegistered('customer-1'));

        $this->assertSame($key, $this->keys->keyFor('customer-1'));
    }
    /**
     * MongoDB's entry factory stores a payload as a subdocument rather than as
     * an encoded string, and this decorator has to work against it without a
     * line of adapter code — the claim the whole feature rests on. The value
     * is rewritten in whichever shape it arrives in.
     */
    public function test_it_works_against_a_factory_that_keeps_the_payload_as_an_array(): void
    {
        $factory = new EncryptingEventEntryFactory(
            new ArrayPayloadEntryFactory(new DefaultEventEntryFactory(new ReflectionEventFactory())),
            $this->keys,
            new SodiumCipher()
        );

        $record = $factory->createFromDomainEvent($this->customerRegistered());
        $payload = $record->toArray()['payload'] ?? null;

        $this->assertIsArray($payload);
        $this->assertIsArray($payload['email'] ?? null, 'The personal field was not sealed in the array shape.');

        $event = $factory->recordToDomainEvent(EventPersistenceRecord::fromArray($record->toArray()));

        $this->assertInstanceOf(CustomerRegistered::class, $event);
        $this->assertSame('ada@example.com', $event->email);
    }

    /**
     * A property can be marked personal and never persisted — it is not in
     * `toArray()`. There is nothing to seal and nothing to fail about; the
     * alternative would be refusing to store an event over a field that was
     * never going to be written.
     */
    public function test_a_personal_property_that_is_never_persisted_is_simply_not_sealed(): void
    {
        $event = new UnpersistedPersonalData(EntityIdentifier::fromString('order-5'), null, 'customer-7', 'secret');
        $event->setVersion(EventVersion::fromInt(1));

        $record = $this->factory()->createFromDomainEvent($event);
        $payload = $record->toArray()['payload'] ?? '';

        $this->assertIsString($payload);
        $this->assertStringNotContainsString('secret', $payload);
    }

    /**
     * The hole this closes. `#[PersonalData]` was matched against the payload
     * by **property name**, and a payload key that did not happen to carry the
     * property's name was simply skipped — silently, with the personal value
     * written beside it in the clear. Nothing failed, nothing warned, and the
     * event looked encrypted from every angle except the stored row.
     *
     * Refused rather than guessed at: several keys can hold the same value,
     * one of them may be the `#[DataSubjectId]` that has to stay readable, and
     * sealing the wrong one is unrecoverable once the row is written. The
     * message names both sides and points at the way to say it explicitly.
     */
    public function test_a_personal_value_the_payload_stores_under_another_key_is_refused(): void
    {
        $event = new CustomerRenamed(EntityIdentifier::fromString('order-7'), null, 'customer-3', 'Ada Lovelace');
        $event->setVersion(EventVersion::fromInt(1));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is marked #[PersonalData] but its value is in the payload under key');

        $this->factory()->createFromDomainEvent($event);
    }

    /**
     * The way to say it explicitly. A payload key is part of the stored data
     * format and does not have to match the property that holds it, so the
     * attribute takes the key when the two differ.
     */
    public function test_a_personal_field_can_name_the_payload_key_it_is_stored_under(): void
    {
        $factory = new EncryptingEventEntryFactory(
            new DefaultEventEntryFactory(),
            $this->keys,
            new SodiumCipher()
        );

        $event = new CustomerContacted(EntityIdentifier::fromString('order-8'), null, 'customer-4', 'ada@example.com');
        $event->setVersion(EventVersion::fromInt(1));

        $record = $factory->createFromDomainEvent($event);
        $payload = $record->toArray()['payload'] ?? '';

        $this->assertIsString($payload);
        $this->assertStringNotContainsString('ada@example.com', $payload, 'The personal data was written in the clear.');

        $read = $factory->recordToDomainEvent(EventPersistenceRecord::fromArray($record->toArray()));

        $this->assertInstanceOf(CustomerContacted::class, $read);
        $this->assertSame('ada@example.com', $read->emailAddress);
    }

    /**
     * An empty personal field has nothing to leak, so nothing is looked for.
     * Searching for it would find every other empty field in the payload and
     * refuse the event over a value that says nothing about anybody.
     */
    public function test_an_empty_personal_field_does_not_make_every_empty_field_look_like_a_leak(): void
    {
        $event = new CustomerRegistered(EntityIdentifier::fromString('order-10'), null, 'customer-6', '', '');
        $event->setVersion(EventVersion::fromInt(1));

        $record = $this->factory()->createFromDomainEvent($event);
        $payload = $record->toArray()['payload'] ?? '';

        $this->assertIsString($payload);
        $this->assertStringContainsString('ciphertext', $payload, 'An empty personal field is still sealed.');
    }

    /**
     * Sealing the field that carries the name is not enough if a second key
     * carries the same value: what is stored is what matters, and one copy in
     * the clear is the whole leak. The event has to be fixed, so it is refused
     * at the point where that is still cheap.
     */
    public function test_a_second_clear_copy_of_a_sealed_value_is_refused(): void
    {
        $event = new CustomerRegisteredTwice(EntityIdentifier::fromString('order-9'), null, 'customer-5', 'ada@example.com');
        $event->setVersion(EventVersion::fromInt(1));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is marked #[PersonalData] but its value is in the payload under key');

        $this->factory()->createFromDomainEvent($event);
    }

    /**
     * An empty subject id would key every such event to one shared key, so one
     * erasure would blank strangers and no erasure would ever be precise.
     */
    public function test_an_empty_subject_id_is_refused(): void
    {
        $event = new CustomerRegistered(EntityIdentifier::fromString('order-6'), null, '', 'ada@example.com', 'ORD-1');
        $event->setVersion(EventVersion::fromInt(1));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must hold a non-empty string; an empty string given');

        $this->factory()->createFromDomainEvent($event);
    }

    /**
     * A row whose payload is neither an encoded string nor a document is a
     * broken row. Passing it on would surface at hydration, naming the event
     * class — while the thing that is wrong is the storage.
     */
    public function test_a_payload_that_is_neither_a_string_nor_a_document_is_reported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be an encoded string or a document; int given');

        $this->factory()->recordToDomainEvent(EventPersistenceRecord::fromArray([
            'event_class' => CustomerRegistered::class,
            'payload' => 42,
        ]));
    }
}

# dummy classes

/**
 * Stands in for MongoDB's factory, which keeps the payload as a subdocument.
 */
final readonly class ArrayPayloadEntryFactory implements EventEntryFactoryInterface
{
    public function __construct(
        private EventEntryFactoryInterface $inner
    ) {
    }

    public function createFromDomainEvent(
        DomainEventInterface $event
    ): EventPersistenceRecord {
        $fields = $this->inner->createFromDomainEvent($event)->toArray();
        $payload = $fields['payload'] ?? '{}';
        $fields['payload'] = json_decode(is_string($payload) ? $payload : '{}', true, 512, JSON_THROW_ON_ERROR);

        return EventPersistenceRecord::fromArray($fields);
    }

    public function recordToDomainEvent(
        EventPersistenceRecord $record
    ): DomainEventInterface {
        $fields = $record->toArray();
        $fields['payload'] = json_encode($fields['payload'] ?? [], JSON_THROW_ON_ERROR);

        return $this->inner->recordToDomainEvent(EventPersistenceRecord::fromArray($fields));
    }
}

final class UnpersistedPersonalData extends SourceEvent
{
    public function __construct(
        ?EntityIdentifierInterface $aggregateId,
        ?EntityIdentifierInterface $eventId,
        #[DataSubjectId]
        public string $customerId = '',
        #[PersonalData]
        public string $neverStored = '',
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
    }

    public function toArray(): array
    {
        return parent::toArray() + ['customerId' => $this->customerId];
    }
}

final class CustomerRegistered extends SourceEvent
{
    public function __construct(
        ?EntityIdentifierInterface $aggregateId,
        ?EntityIdentifierInterface $eventId,
        #[DataSubjectId]
        public string $customerId = '',
        #[PersonalData]
        public string $email = '',
        public string $orderReference = '',
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'customerId' => $this->customerId,
            'email' => $this->email,
            'orderReference' => $this->orderReference,
        ];
    }
}

/**
 * Stores the personal property under a key of its own choosing and says
 * nothing about it.
 */
final class CustomerRenamed extends SourceEvent
{
    public function __construct(
        ?EntityIdentifierInterface $aggregateId,
        ?EntityIdentifierInterface $eventId,
        #[DataSubjectId]
        public string $customerId = '',
        #[PersonalData]
        public string $fullName = '',
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'customerId' => $this->customerId,
            'full_name' => $this->fullName,
        ];
    }
}

/**
 * The same mismatch, declared. The class factory is what maps the stored key
 * back onto the parameter — reflection matches parameter names, and a stored
 * name that differs from one is exactly what `getFactory()` is for.
 */
final class CustomerContacted extends SourceEvent
{
    public function __construct(
        ?EntityIdentifierInterface $aggregateId,
        ?EntityIdentifierInterface $eventId,
        #[DataSubjectId]
        public string $customerId = '',
        #[PersonalData(key: 'email_address')]
        public string $emailAddress = '',
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'customerId' => $this->customerId,
            'email_address' => $this->emailAddress,
        ];
    }

    public static function getFactory(): callable
    {
        return static fn (array $payload): self => new self(
            EntityIdentifier::fromString(is_string($payload['aggregateId'] ?? null) ? $payload['aggregateId'] : ''),
            EntityIdentifier::fromString(is_string($payload['eventId'] ?? null) ? $payload['eventId'] : ''),
            is_string($payload['customerId'] ?? null) ? $payload['customerId'] : '',
            is_string($payload['email_address'] ?? null) ? $payload['email_address'] : ''
        );
    }
}

/**
 * Seals one key and leaves the same value standing under another.
 */
final class CustomerRegisteredTwice extends SourceEvent
{
    public function __construct(
        ?EntityIdentifierInterface $aggregateId,
        ?EntityIdentifierInterface $eventId,
        #[DataSubjectId]
        public string $customerId = '',
        #[PersonalData]
        public string $email = '',
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'customerId' => $this->customerId,
            'email' => $this->email,
            'notificationAddress' => $this->email,
        ];
    }
}

final class CustomerNoted extends SourceEvent
{
    public function __construct(
        ?EntityIdentifierInterface $aggregateId,
        ?EntityIdentifierInterface $eventId,
        #[PersonalData]
        public string $note = '',
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
    }

    public function toArray(): array
    {
        return parent::toArray() + ['note' => $this->note];
    }
}

final class MisdeclaredPersonalData extends SourceEvent
{
    public function __construct(
        ?EntityIdentifierInterface $aggregateId,
        ?EntityIdentifierInterface $eventId,
        #[DataSubjectId]
        public string $customerId = '',
        #[PersonalData]
        public int $nationalIdNumber = 0,
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
    }

    public function toArray(): array
    {
        return parent::toArray() + [
            'customerId' => $this->customerId,
            'nationalIdNumber' => $this->nationalIdNumber,
        ];
    }
}

final class NoPersonalData extends SourceEvent
{
    public function __construct(
        ?EntityIdentifierInterface $aggregateId,
        ?EntityIdentifierInterface $eventId,
        public string $orderReference = '',
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
    }

    public function toArray(): array
    {
        return parent::toArray() + ['orderReference' => $this->orderReference];
    }
}
