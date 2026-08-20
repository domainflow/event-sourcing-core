<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Event;

use DateTimeImmutable;
use DateTimeZone;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\DefaultEventEntryFactory;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventMetadata;
use DomainFlow\EventSourcing\Event\EventPersistenceRecord;
use DomainFlow\EventSourcing\Event\EventTypeRegistry;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use ReflectionException;
use RuntimeException;

#[CoversClass(EventId::class)]
#[CoversClass(EventVersion::class)]
#[CoversClass(OccurredOn::class)]
#[CoversClass(EventPersistenceRecord::class)]
#[CoversClass(DefaultEventEntryFactory::class)]
#[CoversClass(EntityIdentifier::class)]
#[CoversClass(EventEntry::class)]
#[UsesClass(EventTypeRegistry::class)]
#[UsesClass(EventMetadata::class)]
#[UsesTrait(HasEventMetadata::class)]
final class DefaultEventEntryFactoryTest extends TestCase
{
    /**
     * : what is written is the name the code chose, and what is read is
     * resolved back through the same registry.
     */
    public function test_it_writes_the_logical_event_name_and_reads_it_back(): void
    {
        $registry = new EventTypeRegistry();
        $registry->register('order.hydrated', HydratedEvent::class);

        $factory = new DefaultEventEntryFactory(null, null, $registry);
        $record = $factory->createFromDomainEvent(new HydratedEvent('agg-1', 1, ['foo' => 'bar']));

        $this->assertSame(
            'order.hydrated',
            $record->toArray()['event_class'],
            'A class name in the store is what ties the data to the shape of the code.'
        );
        $this->assertInstanceOf(HydratedEvent::class, $factory->recordToDomainEvent($record));
    }

    /**
     * The point of the whole feature: the class moves, the stored data does
     * not, and the events still read.
     */
    public function test_a_renamed_class_still_reads_under_its_logical_name(): void
    {
        $before = new EventTypeRegistry();
        $before->register('order.hydrated', HydratedEvent::class);

        $record = (new DefaultEventEntryFactory(null, null, $before))
            ->createFromDomainEvent(new HydratedEvent('agg-1', 1, ['foo' => 'bar']));

        // The refactoring: same name, different class.
        $after = new EventTypeRegistry();
        $after->register('order.hydrated', RenamedHydratedEvent::class);

        $this->assertInstanceOf(
            RenamedHydratedEvent::class,
            (new DefaultEventEntryFactory(null, null, $after))->recordToDomainEvent($record)
        );
    }

    /**
     * A row whose event type is not even a string cannot name anything, and
     * saying so beats letting it fall through to a confusing class lookup.
     */
    public function test_a_non_string_event_type_is_refused(): void
    {
        $record = EventPersistenceRecord::fromArray([
            'event_class' => 42,
            'aggregate_id' => 'agg-1',
            'event_id' => (string) EventId::generate(),
            'occurred_on' => '2026-08-19 10:00:00.000000',
            'version' => 1,
            'payload' => '{}',
        ]);

        $this->expectException(RuntimeException::class);

        (new DefaultEventEntryFactory())->recordToDomainEvent($record);
    }

    /**
     * Rows written before the registry existed hold a class name. Introducing
     * a registry must not need a migration, which is what makes it cheap to
     * introduce early.
     */
    public function test_a_row_written_before_the_registry_still_reads(): void
    {
        $withoutRegistry = new DefaultEventEntryFactory();
        $record = $withoutRegistry->createFromDomainEvent(new HydratedEvent('agg-1', 1, ['foo' => 'bar']));

        $this->assertSame(HydratedEvent::class, $record->toArray()['event_class']);

        $registry = new EventTypeRegistry();
        $registry->register('order.hydrated', HydratedEvent::class);

        $this->assertInstanceOf(
            HydratedEvent::class,
            (new DefaultEventEntryFactory(null, null, $registry))->recordToDomainEvent($record)
        );
    }

    /**
     * @throws Exception|RandomException|JsonException
     */
    public function test_createFromDomainEvent_with_standard_event(): void
    {
        $aggregateId = $this->createStub(EntityIdentifierInterface::class);
        $aggregateId->method('__toString')->willReturn('abc-456');

        $event = $this->createStub(DomainEventInterface::class);
        $event->method('getAggregateId')->willReturn($aggregateId);
        $event->method('getMetadata')->willReturn(EventMetadata::empty());
        $event->method('getVersion')->willReturn(EventVersion::fromInt(3));
        $event->method('getOccurredOn')->willReturn(new DateTimeImmutable());
        $event->method('toArray')->willReturn([
            'aggregateId' => 'abc-456',
            'version' => 3,
            'name' => 'Joe',
        ]);

        $factory = new DefaultEventEntryFactory();
        $record = $factory->createFromDomainEvent($event);
        $array = $record->toArray();

        $this->assertArrayHasKey('event_id', $array);
        $this->assertSame('abc-456', $array['aggregate_id']);
        $this->assertSame(get_class($event), $array['event_class']);
        $this->assertSame(3, $array['version']);
        $this->assertArrayHasKey('occurred_on', $array);
        $this->assertJson($array['payload']);
    }

    /**
     * A row records which payload schema it was written at, so the read side
     * can tell a payload that has already been migrated from one that has not.
     *
     * @throws Exception|RandomException|JsonException|ReflectionException
     */
    public function test_it_records_the_schema_version_the_event_was_written_at(): void
    {
        $factory = new DefaultEventEntryFactory();

        $record = $factory->createFromDomainEvent(new SchemaMarkedEvent(500, 'GBP'));
        $payload = $record->toArray()['payload'] ?? '';

        $this->assertIsString($payload);
        $this->assertStringContainsString('"' . EventEntry::SCHEMA_VERSION_KEY . '":2', $payload);

        $read = $factory->recordToDomainEvent($record);

        $this->assertInstanceOf(SchemaMarkedEvent::class, $read);
        $this->assertSame('GBP', $read->currency, 'A payload already at the latest schema was migrated again.');
    }

    /**
     * The case the audit found, end to end. A row written at schema 1 sitting
     * at position 27 of its stream: the read side used to compare 27 against
     * `getLatestSchemaVersion()` — a position against a shape —
     * decide the payload was newer than the latest schema, and hydrate the
     * event with a default where the migration would have put a value.
     *
     * A row that carries no marker is read as the first schema, which is what
     * every row written before the marker existed is.
     *
     * @throws Exception|RandomException|JsonException|ReflectionException
     */
    public function test_a_row_written_before_the_schema_marker_is_migrated_wherever_it_sits(): void
    {
        $read = (new DefaultEventEntryFactory())->recordToDomainEvent(EventPersistenceRecord::fromArray([
            'event_id' => (string) EventId::generate(),
            'aggregate_id' => 'agg-legacy',
            'event_class' => SchemaMarkedEvent::class,
            'version' => 27,
            'occurred_on' => '2025-07-05 10:00:00.000000',
            'payload' => json_encode(['amount' => 500], JSON_THROW_ON_ERROR),
            'metadata' => '[]',
        ]));

        $this->assertInstanceOf(SchemaMarkedEvent::class, $read);
        $this->assertSame('EUR', $read->currency, 'A payload written at schema 1 was not migrated.');
    }

    /**
     * `occurred_on` is written without an offset and read back with
     * `OccurredOn::fromString()`, which states UTC. The write side did
     * not: it formatted whatever zone the event's timestamp happened to carry,
     * so an event built with a local `DateTimeImmutable` stored its wall-clock
     * time and every later read moved it by the offset — the two halves of one
     * round trip disagreeing about what the string means.
     *
     * @throws Exception|RandomException|JsonException
     */
    public function test_createFromDomainEvent_writes_the_timestamp_as_utc(): void
    {
        $aggregateId = $this->createStub(EntityIdentifierInterface::class);
        $aggregateId->method('__toString')->willReturn('abc-tz');

        $event = $this->createStub(DomainEventInterface::class);
        $event->method('getAggregateId')->willReturn($aggregateId);
        $event->method('getMetadata')->willReturn(EventMetadata::empty());
        $event->method('getVersion')->willReturn(EventVersion::fromInt(1));
        $event->method('getOccurredOn')->willReturn(
            new DateTimeImmutable('2025-07-05 12:00:00.000000', new DateTimeZone('Europe/Berlin'))
        );
        $event->method('toArray')->willReturn(['aggregateId' => 'abc-tz', 'version' => 1]);

        $record = (new DefaultEventEntryFactory())->createFromDomainEvent($event);

        $this->assertSame(
            '2025-07-05 10:00:00.000000',
            $record->toArray()['occurred_on'],
            'A stored timestamp means UTC, so the zone the event carried has to be applied before it is dropped.'
        );
    }

    /**
     * @throws Exception|RandomException|JsonException
     */
    public function test_createFromDomainEvent_merges_custom_fields(): void
    {
        $aggregateId = $this->createStub(EntityIdentifierInterface::class);
        $aggregateId->method('__toString')->willReturn('user-123');

        $event = new ExtendedEventWithFields();
        $factory = new DefaultEventEntryFactory();
        $record = $factory->createFromDomainEvent($event);
        $array = $record->toArray();

        $this->assertSame('test-value', $array['custom_field']);
        $this->assertSame('extra-value', $array['another_field']);

    }

    /**
     * `getDatabaseFields()` is a duck-typed hook — there is no interface to
     * hold an event to — so what it returns is whatever the event gives back.
     * Letting it into `array_merge()` produced a TypeError naming this class
     * rather than the event that got it wrong.
     *
     * @throws ReflectionException|RandomException|JsonException
     */
    public function test_database_fields_that_are_not_an_array_are_reported(): void
    {
        $factory = new DefaultEventEntryFactory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('getDatabaseFields() must return an array, string given.');

        $factory->createFromDomainEvent(new MisdeclaredFieldsEvent('not-an-array'));
    }

    /**
     * A numeric key would have become a column nobody declared, appended to the
     * record beside the ones that were.
     *
     * @throws ReflectionException|RandomException|JsonException
     */
    public function test_database_fields_keyed_by_position_are_reported(): void
    {
        $factory = new DefaultEventEntryFactory();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return fields keyed by column name; got key int.');

        $factory->createFromDomainEvent(new MisdeclaredFieldsEvent(['value-without-a-column']));
    }

    /**
     * @throws ReflectionException
     */
    public function test_recordToDomainEvent_creates_expected_instance(): void
    {
        $eventInstance = new HydratedEvent('abc-456', 3, ['name' => 'Joe']);

        $record = EventPersistenceRecord::fromArray([
            'event_class' => HydratedEvent::class,
            'aggregate_id' => 'abc-456',
            'event_id' => '11111111-1111-6111-8111-111111111111',
            'occurred_on' => '2024-04-04 12:34:56.789000',
            'version' => 3,
            'payload' => json_encode($eventInstance->toArray()),
        ]);

        $factory = new DefaultEventEntryFactory();
        $restored = $factory->recordToDomainEvent($record);

        $this->assertSame('abc-456', (string) $restored->getAggregateId());
        $this->assertSame(3, $restored->getVersion()->toInt());
    }

    /**
     * @throws ReflectionException
     */
    public function test_recordToDomainEvent_with_invalid_class_throws(): void
    {
        $record = EventPersistenceRecord::fromArray([
            'event_class' => 'NonExistentEventClass',
            'aggregate_id' => 'x',
            'event_id' => 'y',
            'occurred_on' => '2024-01-01 00:00:00.000000',
            'version' => 1,
            'payload' => '{}',
        ]);

        $factory = new DefaultEventEntryFactory();

        $this->expectException(RuntimeException::class);
        $factory->recordToDomainEvent($record);
    }

    /**
     * @throws ReflectionException
     */
    public function test_recordToDomainEvent_defaults_payload_json_when_key_missing(): void
    {
        $record = EventPersistenceRecord::fromArray([
            'event_class' => HydratedEvent::class,
            'aggregate_id' => 'abc-456',
            'event_id' => '22222222-2222-6222-8222-222222222222',
            'occurred_on' => '2024-04-04 12:34:56.789000',
            'version' => 3,
            'payload' => false,
        ]);

        $factory = new DefaultEventEntryFactory();

        $this->expectException(RuntimeException::class);
        $factory->recordToDomainEvent($record);
    }

    /**
     * @throws ReflectionException
     */
    public function test_recordToDomainEvent_falls_back_to_empty_array_on_invalid_json(): void
    {
        $record = EventPersistenceRecord::fromArray([
            'event_class' => HydratedEvent::class,
            'aggregate_id' => 'abc-456',
            'event_id' => '11111111-1111-6111-8111-111111111111',
            'occurred_on' => '2024-04-04 12:34:56.789000',
            'version' => 3,
            'payload' => 'INVALID_JSON',
        ]);

        $factory = new DefaultEventEntryFactory();

        $this->expectException(RuntimeException::class);
        $factory->recordToDomainEvent($record);
    }
    public function test_recordFromArray_creates_event_persistence_record(): void
    {
        $data = [
            'event_id' => 'evt-001',
            'aggregate_id' => 'agg-001',
            'event_class' => HydratedEvent::class,
            'version' => 1,
            'occurred_on' => '2025-01-01 12:00:00.000000',
            'payload' => json_encode([
                'aggregateId' => 'agg-001',
                'version' => 1,
                'payload' => ['key' => 'value'],
            ]),
        ];

        $factory = new DefaultEventEntryFactory();
        $record = $factory->recordFromArray($data);
        $array = $record->toArray();

        $this->assertSame($data['event_id'], $array['event_id']);
        $this->assertSame($data['aggregate_id'], $array['aggregate_id']);
        $this->assertSame($data['event_class'], $array['event_class']);
        $this->assertSame($data['version'], $array['version']);
        $this->assertSame($data['occurred_on'], $array['occurred_on']);
        $this->assertSame($data['payload'], $array['payload']);
    }

    public function test_recordFromArray_preserves_custom_fields(): void
    {
        $data = [
            'event_id' => 'evt-002',
            'aggregate_id' => 'user-123',
            'event_class' => ExtendedEventWithFields::class,
            'version' => 1,
            'occurred_on' => '2025-01-01 13:00:00.000000',
            'payload' => json_encode([
                'aggregateId' => 'user-123',
                'email' => 'test@example.com',
            ]),
            'custom_field' => 'custom-value',
            'another_field' => 'another-value',
        ];

        $factory = new DefaultEventEntryFactory();
        $record = $factory->recordFromArray($data);
        $array = $record->toArray();

        $this->assertSame('custom-value', $array['custom_field']);
        $this->assertSame('another-value', $array['another_field']);
        $this->assertSame('user-123', $array['aggregate_id']);
        $this->assertSame(ExtendedEventWithFields::class, $array['event_class']);
    }

}

class HydratedEvent implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public function __construct(
        private readonly string $aggregateId,
        int $version,
        private readonly array $payload
    ) {
        $this->version = EventVersion::fromInt($version);
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        $id = $this->aggregateId;

        return new class($id) implements EntityIdentifierInterface {
            public function __construct(
                private string $id
            ) {
            }

            public function __toString(): string
            {
                return $this->id;
            }
            public static function fromString(
                string $value
            ): EntityIdentifierInterface {
                return new self($value);
            }
            public function equals(
                EntityIdentifierInterface $other
            ): bool {
                return (string) $other === $this->id;
            }
        };
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function toArray(): array
    {
        return [
            'aggregateId' => $this->aggregateId,
            'version' => $this->version->toInt(),
            'payload' => $this->payload,
        ];
    }
}

final class MisdeclaredFieldsEvent implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public function __construct(
        private readonly mixed $fields
    ) {
        $this->version = EventVersion::fromInt(1);
    }

    public function getDatabaseFields(): mixed
    {
        return $this->fields;
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString('user-123');
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function toArray(): array
    {
        return ['aggregateId' => 'user-123'];
    }
}

final class ExtendedEventWithFields implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public function getAggregateId(): EntityIdentifierInterface
    {
        return new class() implements EntityIdentifierInterface {
            public function __toString(): string
            {
                return 'user-123';
            }
            public static function fromString(
                string $value
            ): EntityIdentifierInterface {
                return new self();
            }
            public function equals(
                EntityIdentifierInterface $other
            ): bool {
                return true;
            }
        };
    }
    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }
    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
    public function toArray(): array
    {
        return ['aggregateId' => 'user-123', 'email' => 'test@example.com'];
    }

    public function getDatabaseFields(): array
    {
        return ['custom_field' => 'test-value', 'another_field' => 'extra-value'];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }

}

final class RenamedHydratedEvent extends HydratedEvent
{
}

/**
 * Versions its payload, and sits wherever its aggregate's stream puts it —
 * two numbers that have nothing to do with each other.
 */
final class SchemaMarkedEvent implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public function __construct(
        public int $amount = 0,
        public string $currency = 'unknown'
    ) {
        $this->version = EventVersion::unassigned();
    }

    public static function getLatestSchemaVersion(): int
    {
        return 2;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function migratePayload(
        array $payload,
        int $fromVersion,
        int $toVersion
    ): array {
        if ($fromVersion < 2) {
            $payload['currency'] = 'EUR';
        }

        return $payload;
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString('agg-schema');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable('2025-07-05 10:00:00.000000', new DateTimeZone('UTC'));
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }

    public function toArray(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }
}
