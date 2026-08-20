<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Event;

use DateTimeZone;
use DomainFlow\EventSourcing\Aggregate\AggregateId;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventEntryFactoryInterface;
use DomainFlow\EventSourcing\Interface\EventFactoryInterface;
use DomainFlow\EventSourcing\Interface\EventUpcasterInterface;
use DomainFlow\Uuid\UuidV6;
use Exception;
use JsonException;
use Random\RandomException;
use ReflectionException;
use RuntimeException;

final class DefaultEventEntryFactory implements EventEntryFactoryInterface
{
    /**
     * @param EventFactoryInterface|null $eventFactory Used when rebuilding a
     *        stored event, instead of the process-wide static EventEntry used
     *        to hold. Two stores in one service each get their own.
     * @param EventUpcasterInterface|null $upcaster Same reasoning.
     * @param EventTypeRegistry|null $eventTypes Maps the class to the name it
     *        is stored under and back. Without one, an event is stored
     *        under its own class name, exactly as before — which is what lets
     *        a codebase adopt the registry one event at a time.
     */
    public function __construct(
        private readonly ?EventFactoryInterface $eventFactory = null,
        private readonly ?EventUpcasterInterface $upcaster = null,
        private readonly ?EventTypeRegistry $eventTypes = null
    ) {
    }

    /**
     * Convert a DomainEvent to EventPersistenceRecord.
     * If event has a getDatabaseFields() method, merge those fields outside payload.
     *
     * @throws JsonException|RandomException
     */
    public function createFromDomainEvent(
        DomainEventInterface $event
    ): EventPersistenceRecord {
        // Reuse the id the event already carries. Minting a new one here made
        // the event_id column and the eventId inside the same row's payload
        // disagree, so an event had no stable identity at all — which is what
        // consumer-side deduplication and idempotency are built on.
        $payload = $event->toArray();
        $eventId = isset($payload['eventId']) && is_string($payload['eventId']) && UuidV6::isValid($payload['eventId'])
            ? UuidV6::fromString($payload['eventId'])
            : UuidV6::generate();

        $payload = $this->withSchemaVersion($payload, $event);

        // Common fields
        $base = [
            'event_id' => $eventId,
            'aggregate_id' => (string) $event->getAggregateId(),
            // The name the code chose, not the name the autoloader owns
            //. Falls back to the class when nothing is registered.
            'event_class' => $this->eventTypes?->nameFor($event::class) ?? $event::class,
            'version' => $event->getVersion()->toInt(),
            // Converted before the offset is dropped. The column carries no
            // offset and is read back with `OccurredOn::fromString()`, which
            // states UTC — so an event built with a local timestamp
            // used to store its wall-clock time and be read back as a
            // different instant, by exactly the offset, every time.
            'occurred_on' => $event->getOccurredOn()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            // Beside the payload, not inside it: an upcaster transforms
            // the domain's data and must not have to preserve infrastructure's.
            'metadata' => json_encode($event->getMetadata()->toArray(), JSON_THROW_ON_ERROR),
        ];

        // If this event defines getDatabaseFields(), merge them as top-level fields
        if (method_exists($event, 'getDatabaseFields')) {
            $base = array_merge($base, $this->assertDatabaseFields($event->getDatabaseFields(), $event::class));
        }

        return EventPersistenceRecord::fromArray($base);
    }

    /**
     * Stamps the payload with the schema it is being written at.
     *
     * An event on its way to storage was just built by its own class, so it is
     * at whatever schema that class declares. Recording it is what lets the
     * read side tell a payload that has already been migrated from one that
     * has not — a question it used to answer with the event's *stream*
     * version, which is a different number entirely, and which said
     * "already migrated" for everything past position 1.
     *
     * Nothing is added for a class that does not version its payload: there is
     * no migration to skip or run, and an unversioned event should not grow a
     * field it will never be asked about. What a class declares is read
     * through `EventEntry`, so both ends of the round trip get the same answer
     * — and the same complaint about a hook that returns something other than
     * an int.
     *
     * @param array<string, mixed> $payload
     * @param DomainEventInterface $event
     * @return array<string, mixed>
     */
    private function withSchemaVersion(
        array $payload,
        DomainEventInterface $event
    ): array {
        $declared = EventEntry::declaredSchemaVersion($event::class);

        if ($declared === null) {
            return $payload;
        }

        $payload[EventEntry::SCHEMA_VERSION_KEY] = $declared;

        return $payload;
    }

    /**
     * The extra columns an event asks to be written beside its payload.
     *
     * A duck-typed hook — there is no interface to hold the event to — so what
     * comes back is whatever it gives back. Checked here rather than let into
     * `array_merge()`, where a non-array is a TypeError naming this class and a
     * numeric key silently becomes a column nobody declared.
     *
     * @param mixed $customFields
     * @param string $eventClass
     * @throws RuntimeException
     * @return array<string, mixed>
     */
    private function assertDatabaseFields(
        mixed $customFields,
        string $eventClass
    ): array {
        if (!is_array($customFields)) {
            throw new RuntimeException(sprintf(
                '%s::getDatabaseFields() must return an array, %s given.',
                $eventClass,
                get_debug_type($customFields)
            ));
        }

        $fields = [];

        foreach ($customFields as $column => $value) {
            if (!is_string($column)) {
                throw new RuntimeException(sprintf(
                    '%s::getDatabaseFields() must return fields keyed by column name; got key %s.',
                    $eventClass,
                    get_debug_type($column)
                ));
            }

            $fields[$column] = $value;
        }

        return $fields;
    }

    /**
     * Convert a persistence record into a DomainEventInterface.
     *
     * @throws Exception|ReflectionException
     */
    public function recordToDomainEvent(
        EventPersistenceRecord $record
    ): DomainEventInterface {
        $row = $record->toArray();

        $eventClass = $row['event_class'] ?? '';
        $aggregateId = $row['aggregate_id'] ?? '';
        $eventId = $row['event_id'] ?? '';
        $occurredOn = $row['occurred_on'] ?? '';
        $version = $row['version'] ?? 0;
        $payloadJson = $row['payload'] ?? '{}';

        if (!is_string($eventClass)) {
            throw new RuntimeException('Stored event type is missing or not a string.');
        }

        // Resolved before the EventEntry is built, not inside it: the upcaster
        // check, migratePayload() and getFactory() all key off the class, and a
        // logical name would silently match none of them.
        $eventClass = $this->eventTypes !== null
            ? $this->eventTypes->classFor($eventClass)
            : $eventClass;

        if (!class_exists($eventClass)) {
            throw new RuntimeException(sprintf('Event class %s not found.', $eventClass));
        }

        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }

        /** @var array<string,mixed> $payload */
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $metadataJson = $row['metadata'] ?? null;
        /** @var array<string, mixed> $metadata */
        $metadata = is_string($metadataJson) ? (json_decode($metadataJson, true) ?: []) : [];

        // Use an EventEntry to do the final reconstitution
        return (new EventEntry(
            eventClass: $eventClass,
            aggregateId: EntityIdentifier::fromString(is_string($aggregateId) ? $aggregateId : (string) AggregateId::generate()),
            eventId: EventId::fromString(is_string($eventId) ? $eventId : (string) EventId::generate()),
            occurredOn: OccurredOn::fromString(is_string($occurredOn) ? $occurredOn : (string) OccurredOn::now()),
            version: EventVersion::fromInt(is_numeric($version) ? (int) $version : EventVersion::new()->toInt()),
            payload: $payload,
            factory: $this->eventFactory,
            upcaster: $this->upcaster,
            metadata: EventMetadata::fromArray(is_array($metadata) ? $metadata : []),
        ))->toDomainEvent();
    }

    /**
     * Convert a persistence record into an associative array.
     *
     * @param array<string, mixed> $row
     * @return EventPersistenceRecord
     */
    public function recordFromArray(
        array $row
    ): EventPersistenceRecord {
        return EventPersistenceRecord::fromArray($row);
    }
}
