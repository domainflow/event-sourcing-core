<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Event;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventMetadata;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventFactoryInterface;
use DomainFlow\EventSourcing\Interface\EventUpcasterInterface;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use RuntimeException;
use stdClass;

#[CoversClass(EventId::class)]
#[CoversClass(EventVersion::class)]
#[CoversClass(OccurredOn::class)]
#[CoversClass(EventEntry::class)]
#[CoversClass(EntityIdentifier::class)]
#[UsesClass(EventMetadata::class)]
#[UsesTrait(HasEventMetadata::class)]
final class EventEntryTest extends TestCase
{
    public function test_from_domain_event_all_fields_provided(): void
    {
        $occurredOn = new DateTimeImmutable('2025-01-01 12:00:00.000000');
        $identifier = new EntityIdentifier('aggregate-1');
        $dummyEvent = new TestEvent($occurredOn, $identifier, 'testData');
        $arrayData = $dummyEvent->toArray();

        $entry = EventEntry::fromDomainEvent($dummyEvent);

        $this->assertSame(TestEvent::class, $entry->eventClass);
        $this->assertSame((string) $identifier, (string) $entry->aggregateId);
        $this->assertSame($arrayData['eventId'], (string) $entry->eventId);
        $this->assertSame($arrayData['occurredOn'], (string) $entry->occurredOn);
        $this->assertSame($arrayData['version'], $entry->version->toInt());
        $this->assertSame($arrayData, $entry->payload);
    }

    /**
     * An entry's timestamp is the instant the event carries, not a string
     * re-read in whatever zone the process happens to run in.
     *
     * `new OccurredOn($string)` with no zone reads the value as local time and
     * converts it, so an event that happened at 10:00 UTC became an entry at
     * 08:00 UTC under `Europe/Berlin` in summer — a value nobody wrote,
     * drifting again on every read-and-store cycle. Every other path in this
     * class states UTC; this one inherited the process's zone.
     *
     * @throws DateMalformedStringException
     */
    public function test_from_domain_event_keeps_the_instant_the_event_carries(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');

        try {
            $event = new TestEvent(
                new OccurredOn('2025-07-05 10:00:00.000000', new DateTimeZone('UTC')),
                new EntityIdentifier('aggregate-tz'),
                'testData'
            );

            $entry = EventEntry::fromDomainEvent($event);

            $this->assertSame('UTC', $entry->occurredOn->getTimezone()->getName());
            $this->assertSame('2025-07-05 10:00:00.000000', (string) $entry->occurredOn);
        } finally {
            date_default_timezone_set($zone);
        }
    }

    /**
     * An event handed over already rebuilt is handed back, not rebuilt again.
     *
     * A storage adapter returns domain events, not rows: the factory, the
     * upcaster and the migration have already run by then. Turning one back
     * into a payload and hydrating the copy repeats work that cannot repeat
     * faithfully — reflection stands in for a factory that is no longer
     * reachable, a payload migration runs a second time, and only what
     * `toArray()` chose to expose survives at all.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_an_already_reconstructed_event_is_handed_back_as_it_is(): void
    {
        $event = new TestEvent(
            new OccurredOn('2025-07-05 10:00:00.000000', new DateTimeZone('UTC')),
            new EntityIdentifier('aggregate-rebuilt'),
            'testData'
        );

        $entry = EventEntry::fromReconstructedEvent($event);

        $this->assertSame($event, $entry->toDomainEvent(), 'The event was rebuilt from a copy of itself.');
        $this->assertSame(1, $entry->version->toInt(), 'The entry still has to describe the event it holds.');
        $this->assertSame('aggregate-rebuilt', (string) $entry->aggregateId);
    }

    public function test_from_domain_event_with_missing_optional_fields(): void
    {
        $minimalEvent = new MinimalEvent();
        $entry = EventEntry::fromDomainEvent($minimalEvent);

        $this->assertSame(MinimalEvent::class, $entry->eventClass);
        $this->assertSame('minimal', (string) $entry->aggregateId);
        $this->assertInstanceOf(EventId::class, $entry->eventId);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}/', (string) $entry->occurredOn);
        $this->assertSame(1, $entry->version->toInt());
        $this->assertSame(['data' => 'minimalData'], $entry->payload);
    }

    /**
     * @throws DateMalformedStringException| ReflectionException
     */
    public function test_rebuild_via_factory(): void
    {
        $payload = [
            'data' => 'factoryData',
            'occurredOn' => '2025-01-02 12:00:00.000000',
            'eventId' => 'ignored',
            'version' => 1,
        ];
        $entry = new EventEntry(
            TestEvent::class,
            EntityIdentifier::fromString('aggregate-factory'),
            EventId::generate(),
            OccurredOn::fromString($payload['occurredOn']),
            EventVersion::fromInt(1),
            $payload,
            new DummyFactory()
        );
        $event = $entry->toDomainEvent();

        $this->assertInstanceOf(TestEvent::class, $event);
        $this->assertSame('factoryData', $event->data);
    }

    /**
     * @throws DateMalformedStringException| ReflectionException
     */
    public function test_to_domain_event_non_existing_class(): void
    {
        $entry = new EventEntry(
            'NonExistentEvent',
            EntityIdentifier::fromString('agg'),
            EventId::generate(),
            OccurredOn::fromString('2025-01-01 00:00:00.000000'),
            EventVersion::fromInt(1),
            []
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Event class NonExistentEvent not found.");
        $entry->toDomainEvent();
    }

    /**
     * @throws DateMalformedStringException| ReflectionException
     */
    public function test_to_domain_event_with_no_constructor(): void
    {
        $entry = new EventEntry(
            NoConstructorEvent::class,
            EntityIdentifier::fromString('agg'),
            EventId::generate(),
            OccurredOn::fromString('2025-01-01 00:00:00.000000'),
            EventVersion::fromInt(1),
            []
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Event class " . NoConstructorEvent::class . " has no constructor.");
        $entry->toDomainEvent();
    }

    /**
     * @throws DateMalformedStringException| ReflectionException
     */
    public function test_rebuild_via_reflection(): void
    {

        $payload = [
            'data' => 'viaReflection',
            'occurredOn' => '2025-01-03 12:00:00.000000',
            'aggregateId' => 'aggregate-reflection',
        ];

        $entry = new EventEntry(
            TestEvent::class,
            EntityIdentifier::fromString('aggregate-reflection'),
            EventId::generate(),
            OccurredOn::fromString('2025-01-03 12:00:00.000000'),
            EventVersion::fromInt(1),
            $payload
        );

        $event = $entry->toDomainEvent();

        $this->assertInstanceOf(TestEvent::class, $event);
        $this->assertSame('viaReflection', $event->data);
        $this->assertSame('aggregate-reflection', (string) $event->getAggregateId());
    }

    /**
     * @throws DateMalformedStringException| ReflectionException
     */
    public function test_rebuild_via_static_factory_method(): void
    {
        $entry = new EventEntry(
            FactoryEvent::class,
            EntityIdentifier::fromString('agg'),
            EventId::generate(),
            OccurredOn::fromString('2025-01-04 12:00:00.000000'),
            EventVersion::fromInt(1),
            []
        );
        $event = $entry->toDomainEvent();

        $this->assertInstanceOf(FactoryEvent::class, $event);
        $this->assertTrue($event->factoryCalled);
    }

    /**
     * @throws DateMalformedStringException| ReflectionException
     */
    public function test_rebuild_throws_if_invalid_instance(): void
    {
        // Keyed 'param' rather than 'data' so the payload leaves nothing
        // unconsumed: since  a surplus key beside an unfilled optional
        // parameter is itself an error, and it would fire before the guard
        // this test is actually about.
        $payload = ['param' => 'bad'];
        $entry = new EventEntry(
            NonDomainEvent::class,
            EntityIdentifier::fromString('agg'),
            EventId::generate(),
            OccurredOn::fromString('2025-01-05 12:00:00.000000'),
            EventVersion::fromInt(1),
            $payload
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Reconstructed event is not an instance of DomainEventInterface.");
        $entry->toDomainEvent();
    }

    /**
     * The version an event carries is its **place in its aggregate's stream**,
     * assigned by `AggregateRoot::applyEvent()`. The version `migratePayload()`
     * asks about is which **shape the payload was written in**. They are
     * different numbers, and this class compared the first against
     * `getLatestSchemaVersion()`.
     *
     * They agree by coincidence at the start of a stream, which is why every
     * test and every fixture here read as if it worked. Past position 1 the
     * coincidence ends: an event at position 27 compared 27 against schema 2,
     * decided it was newer than the latest schema, and skipped a migration it
     * needed. The event then hydrated with defaults where the migration would
     * have put values, and nothing anywhere said so.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_a_legacy_payload_is_migrated_wherever_it_sits_in_the_stream(): void
    {
        $entry = new EventEntry(
            SchemaVersionedEvent::class,
            EntityIdentifier::fromString('aggregate-27'),
            EventId::generate(),
            OccurredOn::fromString('2025-07-05 10:00:00.000000'),
            EventVersion::fromInt(27),
            ['amount' => 500]
        );

        $event = $entry->toDomainEvent();

        $this->assertInstanceOf(SchemaVersionedEvent::class, $event);
        $this->assertSame('EUR', $event->currency, 'A payload written at schema 1 was not migrated.');
    }

    /**
     * A payload that says which schema it was written at is taken at its word,
     * and the marker never reaches the event: it is this package's bookkeeping,
     * not the domain's data, so a constructor knows nothing about it and the
     * surplus-key rule would refuse the event over it.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_a_payload_that_records_its_schema_version_is_not_migrated_again(): void
    {
        $entry = new EventEntry(
            SchemaVersionedEvent::class,
            EntityIdentifier::fromString('aggregate-current'),
            EventId::generate(),
            OccurredOn::fromString('2025-07-05 10:00:00.000000'),
            EventVersion::fromInt(1),
            ['amount' => 500, EventEntry::SCHEMA_VERSION_KEY => 2]
        );

        $event = $entry->toDomainEvent();

        $this->assertInstanceOf(SchemaVersionedEvent::class, $event);
        $this->assertSame('unknown', $event->currency, 'A payload already at the latest schema was migrated again.');
        $this->assertSame(500, $event->amount);
    }

    /**
     * An adapter that keeps the schema version somewhere of its own — a column,
     * a header — states it rather than putting it in the payload. Stated wins:
     * it is the more specific answer, and it is the only one an adapter that
     * has never written the marker can give.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_an_entry_can_be_told_which_schema_its_payload_was_written_at(): void
    {
        $entry = new EventEntry(
            eventClass: SchemaVersionedEvent::class,
            aggregateId: EntityIdentifier::fromString('aggregate-stated'),
            eventId: EventId::generate(),
            occurredOn: OccurredOn::fromString('2025-07-05 10:00:00.000000'),
            version: EventVersion::fromInt(1),
            payload: ['amount' => 500],
            schemaVersion: 2
        );

        $event = $entry->toDomainEvent();

        $this->assertInstanceOf(SchemaVersionedEvent::class, $event);
        $this->assertSame('unknown', $event->currency);
    }

    /**
     * An event held in memory is at the schema its class declares — it was
     * just built by that class's own constructor.
     *
     * @throws DateMalformedStringException
     */
    public function test_from_domain_event_records_the_schema_the_class_declares(): void
    {
        $entry = EventEntry::fromDomainEvent(new SchemaVersionedEvent(500, 'GBP'));

        $this->assertSame(2, $entry->schemaVersion);
    }

    /**
     * @throws DateMalformedStringException| ReflectionException
     */
    /**
     * Both hooks below are duck-typed — there is no interface to hold an event
     * to — so what they return is whatever the event gives back. The cast that
     * used to stand here turned a non-numeric return into 0, which compared
     * less than every stored version, so the payload went through unmigrated
     * and nothing said so.
     */
    public function test_a_schema_version_that_is_not_an_int_is_reported(): void
    {
        $entry = new EventEntry(
            BadSchemaVersionEvent::class,
            EntityIdentifier::fromString('aggregate-bad-schema'),
            EventId::generate(),
            OccurredOn::fromString('2025-01-06 12:00:00.000000'),
            EventVersion::fromInt(1),
            ['data' => 'oldData', 'occurredOn' => '2025-01-06 12:00:00.000000', 'aggregateId' => 'aggregate-bad-schema', 'eventId' => 'x', 'version' => 1]
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('getLatestSchemaVersion() must return an int, string given.');

        $entry->toDomainEvent();
    }

    /**
     * A string here would surface much later, at the event factory, naming a
     * class that did nothing wrong.
     */
    public function test_a_migration_that_does_not_return_an_array_is_reported(): void
    {
        $entry = new EventEntry(
            BadMigrationEvent::class,
            EntityIdentifier::fromString('aggregate-bad-migration'),
            EventId::generate(),
            OccurredOn::fromString('2025-01-06 12:00:00.000000'),
            EventVersion::fromInt(1),
            ['data' => 'oldData', 'occurredOn' => '2025-01-06 12:00:00.000000', 'aggregateId' => 'aggregate-bad-migration', 'eventId' => 'x', 'version' => 1]
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('migratePayload() must return an array, string given.');

        $entry->toDomainEvent();
    }

    /**
     * A list would hydrate the event with every property left at its default —
     * a silent wrong answer rather than a failure.
     */
    public function test_a_migration_that_returns_a_list_is_reported(): void
    {
        $entry = new EventEntry(
            ListMigrationEvent::class,
            EntityIdentifier::fromString('aggregate-list-migration'),
            EventId::generate(),
            OccurredOn::fromString('2025-01-06 12:00:00.000000'),
            EventVersion::fromInt(1),
            ['data' => 'oldData', 'occurredOn' => '2025-01-06 12:00:00.000000', 'aggregateId' => 'aggregate-list-migration', 'eventId' => 'x', 'version' => 1]
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return a payload keyed by property name; got key int.');

        $entry->toDomainEvent();
    }

    public function test_migrate_payload_applies_changes(): void
    {
        $payload = [
            'data' => 'oldData',
            'occurredOn' => '2025-01-06 12:00:00.000000',
            'aggregateId' => 'aggregate-migratable',
            'eventId' => 'migratableEventId',
            'version' => 1,
        ];

        $entry = new EventEntry(
            MigratableEvent::class,
            EntityIdentifier::fromString('aggregate-migratable'),
            EventId::generate(),
            OccurredOn::fromString($payload['occurredOn']),
            EventVersion::fromInt(1),
            $payload
        );

        $event = $entry->toDomainEvent();
        $this->assertInstanceOf(MigratableEvent::class, $event);
        $this->assertSame('newData', $event->data);
    }

    /**
     * @throws DateMalformedStringException| ReflectionException
     */
    public function test_migrate_payload_returns_unchanged_if_no_method(): void
    {
        $payload = [
            'data' => 'unchanged',
            'occurredOn' => '2025-01-07 12:00:00.000000',
            'aggregateId' => 'agg',
            'eventId' => 'noMigrationEventId',
            'version' => 1,
        ];

        $entry = new EventEntry(
            NoMigrationMethodEvent::class,
            EntityIdentifier::fromString('agg'),
            EventId::generate(),
            OccurredOn::fromString($payload['occurredOn']),
            EventVersion::fromInt(1),
            $payload
        );

        $event = $entry->toDomainEvent();
        $this->assertInstanceOf(NoMigrationMethodEvent::class, $event);
        $this->assertSame('unchanged', $event->data);
    }

    /**
     * @throws ReflectionException
     */
    public function test_resolve_constructor_arguments_handles_all_types(): void
    {
        $payload = [
            'occurredOn' => '2025-01-01 00:00:00.000000',
            'aggregateId' => 'agg-123',
            'data' => 'abc',
        ];
        $entry = new EventEntry(
            TestEvent::class,
            EntityIdentifier::fromString('agg-123'),
            EventId::generate(),
            OccurredOn::fromString('2025-01-01 00:00:00.000000'),
            EventVersion::fromInt(1),
            $payload
        );

        $reflection = new ReflectionClass(TestEvent::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $args = (new ReflectionMethod($entry, 'resolveConstructorArguments'))
            ->invoke($entry, $constructor, $payload);

        $this->assertIsArray($args);
        $this->assertCount(3, $args);
        $this->assertInstanceOf(DateTimeImmutable::class, $args[0]);
        $this->assertInstanceOf(EntityIdentifierInterface::class, $args[1]);
        $this->assertSame('abc', $args[2]);
    }

    /**
     * @throws DateMalformedStringException
     * @throws ReflectionException
     */
    public function test_rebuild_via_class_factory_falls_back_to_reflection_if_factory_returns_null(): void
    {
        $payload = [
            'data' => 'reflectionFallback',
            'occurredOn' => '2025-01-10 10:00:00.000000',
            'aggregateId' => 'agg',
        ];

        $entry = new EventEntry(
            FakeFactoryNull::class,
            EntityIdentifier::fromString('agg'),
            EventId::generate(),
            OccurredOn::fromString($payload['occurredOn']),
            EventVersion::fromInt(1),
            $payload
        );

        $event = $entry->toDomainEvent();

        $this->assertInstanceOf(FakeFactoryNull::class, $event);
        $this->assertSame('reflectionFallback', $event->data);
    }

    /**
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_rebuild_via_class_factory_throws_if_not_domain_event_instance(): void
    {
        $payload = [
            'data' => 'invalidType',
            'occurredOn' => '2025-01-11 11:11:11.000000',
        ];

        $entry = new EventEntry(
            FakeFactoryReturnsNonDomainEvent::class,
            EntityIdentifier::fromString('agg'),
            EventId::generate(),
            OccurredOn::fromString($payload['occurredOn']),
            EventVersion::fromInt(1),
            $payload
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Reconstructed event is not an instance of DomainEventInterface.");
        $entry->toDomainEvent();
    }

    /**
     * @throws ReflectionException
     */
    public function test_rebuild_via_factory_throws_if_factory_is_null(): void
    {
        $entry = new EventEntry(TestEvent::class, EntityIdentifier::fromString('agg'), EventId::generate(), OccurredOn::fromString('2025-01-01 00:00:00.000000'), EventVersion::fromInt(1), []);

        $method = new ReflectionMethod($entry, 'rebuildViaFactory');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Event factory is not set.');

        $method->invoke($entry, []);
    }

    /**
     * @throws ReflectionException
     */
    public function test_resolve_constructor_arguments_throws_on_missing_required_field(): void
    {
        $payload = [];

        $entry = new EventEntry(
            TestEventWithOptionalAggregateIdAndOccurredOn::class,
            EntityIdentifier::fromString('agg-test'),
            EventId::generate(),
            OccurredOn::fromString((new DateTimeImmutable())->format(EventEntry::DATE_FORMAT)),
            EventVersion::fromInt(1),
            $payload
        );

        $reflection = new ReflectionClass(TestEventWithOptionalAggregateIdAndOccurredOn::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $method = new ReflectionMethod($entry, 'resolveConstructorArguments');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required payload field "data"');
        $method->invoke($entry, $constructor, $payload);
    }

    /**
     * @throws ReflectionException
     */
    public function test_resolve_constructor_arguments_defaults_to_now_if_occurred_on_missing(): void
    {
        $payload = [
            'aggregateId' => 'agg-default-date',
            'data' => 'checkNowFallback',
        ];

        $entry = new EventEntry(
            TestEventWithOptionalAggregateIdAndOccurredOn::class,
            EntityIdentifier::fromString('agg-default-date'),
            EventId::generate(),
            OccurredOn::fromString((new DateTimeImmutable())->format(EventEntry::DATE_FORMAT)),
            EventVersion::fromInt(1),
            $payload
        );

        $constructor = (new ReflectionClass(TestEventWithOptionalAggregateIdAndOccurredOn::class))->getConstructor();
        $args = (new ReflectionMethod($entry, 'resolveConstructorArguments'))->invoke($entry, $constructor, $payload);

        $this->assertSame('checkNowFallback', $args[0]);
        $this->assertInstanceOf(DateTimeImmutable::class, $args[1]);
    }

    /**
     * @throws ReflectionException
     */
    public function test_resolve_constructor_arguments_falls_back_to_aggregate_id_if_missing(): void
    {
        $payload = [
            'occurredOn' => '2025-01-01 00:00:00.000000',
            'data' => 'checkFallbackAggregate',
        ];

        $entry = new EventEntry(
            TestEventWithOptionalAggregateIdAndOccurredOn::class,
            EntityIdentifier::fromString('agg-fallback'),
            EventId::generate(),
            OccurredOn::fromString('2025-01-01 00:00:00.000000'),
            EventVersion::fromInt(1),
            $payload
        );

        $constructor = (new ReflectionClass(TestEventWithOptionalAggregateIdAndOccurredOn::class))->getConstructor();
        $args = (new ReflectionMethod($entry, 'resolveConstructorArguments'))->invoke($entry, $constructor, $payload);

        $this->assertSame('checkFallbackAggregate', $args[0]);
        $this->assertInstanceOf(DateTimeImmutable::class, $args[1]);
        $this->assertInstanceOf(EntityIdentifierInterface::class, $args[2]);
        $this->assertSame('agg-fallback', (string) $args[2]);
    }

    /**
     * @throws ReflectionException
     */
    public function test_validate_and_convert_scalar_accepts_valid_types(): void
    {
        $entry = new EventEntry('Dummy', EntityIdentifier::fromString('agg-1'), EventId::generate(), OccurredOn::fromString('2025-01-01 00:00:00.000000'), EventVersion::fromInt(1), []);
        $method = new ReflectionMethod($entry, 'validateAndConvertScalar');

        $this->assertSame('abc', $method->invoke($entry, 'param1', 'string', 'abc'));
        $this->assertSame(123, $method->invoke($entry, 'param2', 'int', '123'));
        $this->assertSame(3.14, $method->invoke($entry, 'param3', 'float', '3.14'));
        $this->assertTrue($method->invoke($entry, 'param4', 'bool', 1));
        $this->assertFalse($method->invoke($entry, 'param5', 'bool', '0'));
    }

    /**
     * @throws ReflectionException
     */
    public function test_validate_and_convert_scalar_throws_on_invalid(): void
    {
        $entry = new EventEntry('Dummy', EntityIdentifier::fromString('agg-1'), EventId::generate(), OccurredOn::fromString('2025-01-01 00:00:00.000000'), EventVersion::fromInt(1), []);
        $method = new ReflectionMethod($entry, 'validateAndConvertScalar');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Expected int for 'param'");
        $method->invoke($entry, 'param', 'int', 'not-an-int');
    }

    /**
     * @throws ReflectionException
     */
    public function test_resolve_special_type_handles_datetime(): void
    {
        $entry = new EventEntry('Dummy', EntityIdentifier::fromString('agg-1'), EventId::generate(), OccurredOn::fromString('2025-01-01 00:00:00.000000'), EventVersion::fromInt(1), []);
        $method = new ReflectionMethod($entry, 'resolveSpecialType');

        $result = $method->invoke($entry, DateTimeImmutable::class, '2025-01-01 00:00:00');
        $this->assertInstanceOf(DateTimeImmutable::class, $result);
    }

    /**
     * @throws ReflectionException
     */
    public function test_resolve_special_type_handles_entity_identifier(): void
    {
        $entry = new EventEntry('Dummy', EntityIdentifier::fromString('agg-fallback'), EventId::generate(), OccurredOn::fromString('2025-01-01 00:00:00.000000'), EventVersion::fromInt(1), []);
        $method = new ReflectionMethod($entry, 'resolveSpecialType');

        $result = $method->invoke($entry, EntityIdentifierInterface::class, 'agg-explicit');

        $this->assertInstanceOf(EntityIdentifierInterface::class, $result);
        $this->assertSame('agg-explicit', (string) $result);

        $fallback = $method->invoke($entry, EntityIdentifierInterface::class, null);

        $this->assertInstanceOf(EntityIdentifierInterface::class, $fallback);
        $this->assertSame('agg-fallback', (string) $fallback);
    }

    /**
     * @throws ReflectionException
     */
    public function test_resolve_special_type_handles_event_version(): void
    {
        $entry = new EventEntry('Dummy', EntityIdentifier::fromString('agg-1'), EventId::generate(), OccurredOn::fromString('2025-01-01 00:00:00.000000'), EventVersion::fromInt(1), []);
        $method = new ReflectionMethod($entry, 'resolveSpecialType');

        $result = $method->invoke($entry, EventVersion::class, 4);
        $this->assertInstanceOf(EventVersion::class, $result);
        $this->assertSame(4, $result->toInt());

        $fallback = $method->invoke($entry, EventVersion::class, 'not-numeric');
        $this->assertInstanceOf(EventVersion::class, $fallback);
        $this->assertSame(1, $fallback->toInt());
    }

    /**
     * @throws ReflectionException
     */
    public function test_validate_and_convert_scalar_throws_if_string_expected_but_array_given(): void
    {
        $entry = new EventEntry('Dummy', EntityIdentifier::fromString('agg-x'), EventId::generate(), OccurredOn::fromString('2025-01-01 00:00:00.000000'), EventVersion::fromInt(1), []);
        $method = new ReflectionMethod($entry, 'validateAndConvertScalar');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Expected string for 'fieldStr'");

        $method->invoke($entry, 'fieldStr', 'string', ['not', 'a', 'string']);
    }

    /**
     * @throws ReflectionException
     */
    public function test_validate_and_convert_scalar_throws_if_float_expected_but_invalid(): void
    {
        $entry = new EventEntry('Dummy', EntityIdentifier::fromString('agg-x'), EventId::generate(), OccurredOn::fromString('2025-01-01 00:00:00.000000'), EventVersion::fromInt(1), []);
        $method = new ReflectionMethod($entry, 'validateAndConvertScalar');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Expected float for 'fieldFloat'");

        $method->invoke($entry, 'fieldFloat', 'float', 'not-a-float');
    }

    /**
     * @throws ReflectionException
     */
    public function test_validate_and_convert_scalar_throws_if_bool_expected_but_invalid(): void
    {
        $entry = new EventEntry('Dummy', EntityIdentifier::fromString('agg-x'), EventId::generate(), OccurredOn::fromString('2025-01-01 00:00:00.000000'), EventVersion::fromInt(1), []);
        $method = new ReflectionMethod($entry, 'validateAndConvertScalar');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Expected bool for 'fieldBool'");

        $method->invoke($entry, 'fieldBool', 'bool', 'maybe');
    }

    /**
     * @throws ReflectionException
     */
    public function test_validate_and_convert_scalar_fallback_for_unknown_type(): void
    {
        $entry = new EventEntry('Dummy', EntityIdentifier::fromString('agg-x'), EventId::generate(), OccurredOn::fromString('2025-01-01 00:00:00.000000'), EventVersion::fromInt(1), []);
        $method = new ReflectionMethod($entry, 'validateAndConvertScalar');

        $result = $method->invoke($entry, 'fieldCustom', 'customType', 'customValue');
        $this->assertSame('customValue', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function test_resolve_special_type_returns_value_for_unknown_class(): void
    {
        $entry = new EventEntry('Dummy', EntityIdentifier::fromString('agg-x'), EventId::generate(), OccurredOn::fromString('2025-01-01 00:00:00.000000'), EventVersion::fromInt(1), []);
        $method = new ReflectionMethod($entry, 'resolveSpecialType');

        $dummyObject = new stdClass();
        $result = $method->invoke($entry, stdClass::class, $dummyObject);

        $this->assertSame($dummyObject, $result);
    }

    /**
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_to_domain_event_applies_upcaster_when_supported(): void
    {
        $payload = [
            'foo' => 'bar',
        ];

        $entry = new EventEntry(
            UpcastedEvent::class,
            EntityIdentifier::fromString('agg-upcast'),
            EventId::generate(),
            OccurredOn::fromString('2025-01-12 12:00:00.000000'),
            EventVersion::fromInt(1),
            $payload
        );

        $upcaster = new class() implements EventUpcasterInterface {
            public function supports(string $eventType): bool
            {
                return $eventType === UpcastedEvent::class;
            }

            public function upcast(string $eventType, array $data): DomainEventInterface
            {
                return new UpcastedEvent();
            }
        };

        $event = $entry->withReconstructionRules(null, $upcaster)->toDomainEvent();

        $this->assertInstanceOf(UpcastedEvent::class, $event);
    }

    /**
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_rebuild_via_class_factory_uses_callable(): void
    {
        $entry = new EventEntry(
            CallableFactoryEvent::class,
            EntityIdentifier::fromString('agg-callable'),
            EventId::generate(),
            OccurredOn::fromString('2025-01-15 15:00:00.000000'),
            EventVersion::fromInt(1),
            ['data' => 'fromCallable']
        );

        $event = $entry->toDomainEvent();

        $this->assertInstanceOf(CallableFactoryEvent::class, $event);
        $this->assertSame('fromCallable', $event->data);
    }

    /**
     * @throws ReflectionException|DateMalformedStringException
     */
    public function test_rebuild_via_class_factory_throws_on_invalid_factory(): void
    {
        $entry = new EventEntry(
            InvalidFactoryEvent::class,
            EntityIdentifier::fromString('agg-invalid'),
            EventId::generate(),
            OccurredOn::fromString('2025-01-16 16:00:00.000000'),
            EventVersion::fromInt(1),
            ['data' => 'fail']
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Invalid factory returned from ' . InvalidFactoryEvent::class . '::getFactory()'
        );

        $entry->toDomainEvent();
    }

    public function test_eventId_toUuid_returnsEquivalentUuid(): void
    {
        $eventId = EventId::generate();

        $this->assertSame((string) $eventId, (string) $eventId->toUuid());
    }

}

# dummy classes
final class UpcastedEvent implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public function getAggregateId(): EntityIdentifierInterface
    {
        return new EntityIdentifier('upcasted');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(999);
    }

    public function toArray(): array
    {
        return [];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class TestEvent implements DomainEventInterface
{
    use HasEventMetadata;

    public DateTimeImmutable $occurredOn;
    public EntityIdentifierInterface $aggregateId;
    public string $data;
    protected EventVersion $version;

    public function __construct(
        DateTimeImmutable $occurredOn,
        EntityIdentifierInterface $aggregateId,
        string $data = "default"
    ) {
        $this->occurredOn = $occurredOn;
        $this->aggregateId = $aggregateId;
        $this->data = $data;
        $this->version = EventVersion::fromInt(1);
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->aggregateId;
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    public function toArray(): array
    {
        return [
            'eventId' => '1ec9414c-232a-6b00-b3c8-9e6bdeced846',
            'occurredOn' => $this->occurredOn->format(EventEntry::DATE_FORMAT),
            'version' => $this->version->toInt(),
            'data' => $this->data,
        ];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class NonDomainEvent
{
    public function __construct(
        $param = null
    ) {
    }
}

class NoConstructorEvent implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public function getAggregateId(): EntityIdentifierInterface
    {
        return new EntityIdentifier('dummy');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return [];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class FactoryEvent implements DomainEventInterface
{
    use HasEventMetadata;

    public bool $factoryCalled = false;
    protected EventVersion $version;

    public function __construct(
        $dummy = null
    ) {
    }

    public static function getFactory()
    {
        return new class() implements EventFactoryInterface {
            public function createFromPayload(
                string $eventClass,
                array $payload
            ): DomainEventInterface {
                $event = new FactoryEvent();
                $event->factoryCalled = true;

                return $event;
            }
        };
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return new EntityIdentifier('factory');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return [];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

abstract class MisdeclaringMigratableEvent implements DomainEventInterface
{
    use HasEventMetadata;

    public string $data;

    protected EventVersion $version;

    public function __construct(
        DateTimeImmutable $occurredOn,
        EntityIdentifierInterface $aggregateId,
        string $data
    ) {
        $this->data = $data;
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return new EntityIdentifier('misdeclaring');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(2);
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }

    public function toArray(): array
    {
        return [];
    }
}

final class BadSchemaVersionEvent extends MisdeclaringMigratableEvent
{
    public static function getLatestSchemaVersion(): mixed
    {
        return '2';
    }
}

final class BadMigrationEvent extends MisdeclaringMigratableEvent
{
    public static function getLatestSchemaVersion(): mixed
    {
        return 2;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function migratePayload(
        array $payload,
        int $fromVersion,
        int $toVersion
    ): mixed {
        return 'not-a-payload';
    }
}

final class ListMigrationEvent extends MisdeclaringMigratableEvent
{
    public static function getLatestSchemaVersion(): mixed
    {
        return 2;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function migratePayload(
        array $payload,
        int $fromVersion,
        int $toVersion
    ): mixed {
        return ['newData'];
    }
}

final class MigratableEvent implements DomainEventInterface
{
    use HasEventMetadata;

    public string $data;

    protected EventVersion $version;

    public function __construct(
        DateTimeImmutable $occurredOn,
        EntityIdentifierInterface $aggregateId,
        string $data
    ) {
        $this->data = $data;
    }

    public static function getLatestSchemaVersion(): int
    {
        return 2;
    }

    public static function migratePayload(
        array $payload,
        int $fromVersion,
        int $toVersion
    ): array {
        $payload['data'] = 'newData';

        return $payload;
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return new EntityIdentifier('migratable');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(2);
    }

    public function toArray(): array
    {
        return [
            'eventId' => 'migratableEventId',
            'occurredOn' => (new DateTimeImmutable())->format(EventEntry::DATE_FORMAT),
            'version' => 1,
            'data' => $this->data,
        ];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

/**
 * Declares a payload schema of its own, and a migration onto it. Its stream
 * position and its schema version are deliberately unrelated numbers.
 */
final class SchemaVersionedEvent implements DomainEventInterface
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
        return new EntityIdentifier('priced');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable('2025-07-05 10:00:00.000000');
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

final class MinimalEvent implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public function getAggregateId(): EntityIdentifierInterface
    {
        return new EntityIdentifier('minimal');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return [
            'data' => 'minimalData',
        ];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class DummyFactory implements EventFactoryInterface
{
    /**
     * @throws DateMalformedStringException
     */
    public function createFromPayload(
        string $eventClass,
        array $payload
    ): DomainEventInterface {
        $occurredOn = new DateTimeImmutable(
            isset($payload['occurredOn']) ? $payload['occurredOn'] : (
            new DateTimeImmutable())->format(
                EventEntry::DATE_FORMAT
            )
        );
        $id = new EntityIdentifier('factoryTest');
        $data = $payload['data'] ?? 'factoryDefault';

        return new TestEvent($occurredOn, $id, $data);
    }
}

final class NoMigrationMethodEvent implements DomainEventInterface
{
    use HasEventMetadata;

    public string $data;

    protected EventVersion $version;

    public function __construct(
        DateTimeImmutable $occurredOn,
        EntityIdentifierInterface $aggregateId,
        string $data
    ) {
        $this->data = $data;
    }

    public static function getLatestSchemaVersion(): int
    {
        return 2;
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return new EntityIdentifier('noMigrationMethod');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return [
            'eventId' => 'noMigrationEventId',
            'occurredOn' => (new DateTimeImmutable())->format(EventEntry::DATE_FORMAT),
            'version' => 1,
            'data' => $this->data,
        ];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class FakeFactoryNull implements DomainEventInterface
{
    use HasEventMetadata;

    public string $data;
    protected EventVersion $version;

    public function __construct(
        DateTimeImmutable $occurredOn,
        EntityIdentifierInterface $aggregateId,
        string $data
    ) {
        $this->data = $data;
    }

    public static function getFactory(): ?object
    {
        return null;
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return new EntityIdentifier('agg');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return [
            'eventId' => 'fakeFactoryNull',
            'occurredOn' => (new DateTimeImmutable())->format(EventEntry::DATE_FORMAT),
            'version' => 1,
            'data' => $this->data,
        ];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class FakeFactoryReturnsNonDomainEvent implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public static function getFactory(): object
    {
        return new class() {
            public function createFromPayload(
                string $eventClass,
                array $payload
            ): object {
                return new stdClass();
            }
        };
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return new EntityIdentifier('agg');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return [];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class TestEventWithOptionalAggregateIdAndOccurredOn implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public function __construct(
        public string $data,
        ?DateTimeImmutable $occurredOn = null,
        ?EntityIdentifierInterface $aggregateId = null,
    ) {
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->aggregateId ?? new EntityIdentifier('fallback');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return $this->occurredOn ?? new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return ['data' => $this->data];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class CallableFactoryEvent implements DomainEventInterface
{
    use HasEventMetadata;

    public string $data;

    protected EventVersion $version;

    public static function getFactory(): callable
    {
        return function (array $payload): DomainEventInterface {
            $event = new CallableFactoryEvent();
            $event->data = $payload['data'] ?? 'default';

            return $event;
        };
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return new EntityIdentifier('agg-callable');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return ['data' => $this->data];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class InvalidFactoryEvent implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public static function getFactory(): mixed
    {
        return 'this-is-not-a-factory';
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return new EntityIdentifier('agg-invalid');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return [];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}
