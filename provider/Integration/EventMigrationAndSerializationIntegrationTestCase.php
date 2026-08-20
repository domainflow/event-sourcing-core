<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Integration;

use DateMalformedStringException;
use DateTimeImmutable;
use DomainFlow\EventSourcing\Concurrency\ConcurrencyCheckingStorage;
use DomainFlow\EventSourcing\Concurrency\MaxVersionStrategy;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventSerializerInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use RuntimeException;

abstract class EventMigrationAndSerializationIntegrationTestCase extends TestCase
{
    abstract protected function getStorage(): EventStorageInterface;

    /**
     * @param string $eventId
     * @param EntityIdentifier $aggregateId
     * @param string $eventClass
     * @param int $version
     * @param string $occurredOn
     * @param array<string, mixed> $payload
     */
    abstract protected function insertEvent(string $eventId, EntityIdentifier $aggregateId, string $eventClass, int $version, string $occurredOn, array $payload): void;
    abstract protected function insertLegacyEvent(EntityIdentifier $aggregateId, string $eventId, string $occurredOn): void;

    /**
     * A row written by an application already on the current schema.
     *
     * Expressed here rather than left to each adapter (it used to be a third
     * abstract method) because what makes a row current is now part of the
     * stored payload — `EventEntry::SCHEMA_VERSION_KEY` — and an adapter
     * building this payload by hand had no way to know that. Adapters can
     * delete their own `insertNewEventData()`; `insertEvent()` is all this
     * needs.
     *
     * @param EntityIdentifier $aggregateId
     * @param string $eventId
     * @param string $occurredOn
     * @param string $description
     * @return void
     */
    protected function insertNewEventData(
        EntityIdentifier $aggregateId,
        string $eventId,
        string $occurredOn,
        string $description
    ): void {
        $this->insertEvent(
            $eventId,
            $aggregateId,
            MigratableDummyEvent::class,
            2,
            $occurredOn,
            [
                'aggregateId' => (string) $aggregateId,
                'eventId' => $eventId,
                'occurredOn' => $occurredOn,
                'version' => 2,
                'delta' => 7,
                'description' => $description,
                EventEntry::SCHEMA_VERSION_KEY => 2,
            ]
        );
    }

    /**
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_eventMigrationAndSerializationWorks(): void
    {
        $aggregateId = EntityIdentifier::fromString('agg-migrate-1');
        $legacyEventId = (string) EventId::generate();
        $occurredOn = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');

        $this->insertLegacyEvent($aggregateId, $legacyEventId, $occurredOn);

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $retrievedEvents = $eventStorage->retrieveEvents($aggregateId);
        $this->assertCount(1, $retrievedEvents);

        /** @var MigratableDummyEvent $event */
        $event = $retrievedEvents[0];
        $this->assertInstanceOf(MigratableDummyEvent::class, $event);

        $this->assertSame(3, $event->getDelta());
        $this->assertSame('default description', $event->getDescription());

        $this->assertSame(1, $event->getVersion()->toInt());

        $serializer = new DummyEventSerializer();
        $serialized = $serializer->serialize($event);
        $this->assertNotEmpty($serialized);

        $deserializedEvent = $serializer->deserialize($serialized);
        $this->assertInstanceOf(MigratableDummyEvent::class, $deserializedEvent);
        $this->assertSame(3, $deserializedEvent->getDelta());
        $this->assertSame('default description', $deserializedEvent->getDescription());
        $this->assertSame(1, $deserializedEvent->getVersion()->toInt());
    }

    /**
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_newEventSerializationWorksProperly(): void
    {
        $aggregateId = EntityIdentifier::fromString('agg-new-1');
        $eventId = (string) EventId::generate();
        $occurredOn = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');

        $this->insertEvent(
            $eventId,
            $aggregateId,
            MigratableDummyEvent::class,
            2,
            $occurredOn,
            [
                'aggregateId' => (string) $aggregateId,
                'eventId' => $eventId,
                'occurredOn' => $occurredOn,
                'version' => 2,
                'delta' => 10,
                'description' => 'custom description',
                // What marks a payload as already written at the latest
                // schema. Without it the row reads as the first schema and is
                // migrated — which is exactly what should happen to a row that
                // predates the marker, and exactly what must not happen to
                // this one.
                EventEntry::SCHEMA_VERSION_KEY => 2,
            ]
        );

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $retrievedEvents = $eventStorage->retrieveEvents($aggregateId);
        $this->assertCount(1, $retrievedEvents);

        /** @var MigratableDummyEvent $event */
        $event = $retrievedEvents[0];
        $this->assertSame(10, $event->getDelta());
        $this->assertSame('custom description', $event->getDescription());
        $this->assertSame(2, $event->getVersion()->toInt());

        $serializer = new DummyEventSerializer();
        $serialized = $serializer->serialize($event);
        $deserializedEvent = $serializer->deserialize($serialized);
        $this->assertInstanceOf(MigratableDummyEvent::class, $deserializedEvent);
        $this->assertSame(10, $deserializedEvent->getDelta());
        $this->assertSame('custom description', $deserializedEvent->getDescription());
        $this->assertSame(2, $deserializedEvent->getVersion()->toInt());
    }

    /**
     * @throws ReflectionException|DateMalformedStringException
     */
    public function test_deserializationMultipleEvents_mixedVersions(): void
    {
        $aggregateId = EntityIdentifier::fromString('agg-mixed-1');
        $occurredOn1 = (new DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s.u');
        $occurredOn2 = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');

        $legacyEventId = (string) EventId::generate();
        $newEventId = (string) EventId::generate();

        $this->insertLegacyEvent($aggregateId, $legacyEventId, $occurredOn1);
        $this->insertNewEventData($aggregateId, $newEventId, $occurredOn2, 'explicit description');

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());
        $retrievedEvents = $eventStorage->retrieveEvents($aggregateId);
        $this->assertCount(2, $retrievedEvents);

        $serializer = new DummyEventSerializer();
        $deserialized = [];
        foreach ($retrievedEvents as $event) {
            $serialized = $serializer->serialize($event);
            $deserialized[] = $serializer->deserialize($serialized);
        }

        /** @var MigratableDummyEvent $legacyEvent */
        $legacyEvent = $deserialized[0];
        $this->assertSame(3, $legacyEvent->getDelta());
        $this->assertSame('default description', $legacyEvent->getDescription());
        $this->assertSame(1, $legacyEvent->getVersion()->toInt());

        /** @var MigratableDummyEvent $newEvent */
        $newEvent = $deserialized[1];
        $this->assertSame(7, $newEvent->getDelta());
        $this->assertSame('explicit description', $newEvent->getDescription());
        $this->assertSame(2, $newEvent->getVersion()->toInt());
    }

    /**
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_errorOnInvalidPayload_duringDeserialization(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $invalidPayload = json_encode([
            'aggregateId' => 'agg-invalid',
            'eventId' => 'event-invalid',
            'occurredOn' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            'version' => 2,
            'description' => 'oops',
        ]);

        $serializer = new DummyEventSerializer();
        $serializer->deserialize((string) $invalidPayload);
    }
}

// Dummy classes
final class MigratableDummyEvent extends SourceEvent
{
    private int $delta;
    private string $description;

    public function __construct(
        EntityIdentifierInterface $aggregateId,
        string $eventId,
        int $delta,
        string $description,
        ?DateTimeImmutable $occurredOn = null,
        int $version = 2
    ) {
        parent::__construct($aggregateId, EntityIdentifier::fromString($eventId), $occurredOn, EventVersion::fromInt($version));
        $this->delta = $delta;
        $this->description = $description;
    }

    public function getDelta(): int
    {
        return $this->delta;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function toArray(): array
    {
        $base = parent::toArray();
        $base['delta'] = $this->delta;
        $base['description'] = $this->description;

        return $base;
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
        if ($fromVersion === 1 && $toVersion === 2) {
            $payload['description'] = 'default description';
        }

        return $payload;
    }
}

final class DummyEventSerializer implements EventSerializerInterface
{
    public function serialize(
        DomainEventInterface $event
    ): string {
        return json_encode($event->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * @throws ReflectionException
     * @throws DateMalformedStringException
     */
    public function deserialize(
        string $data
    ): DomainEventInterface {
        $decoded = json_decode($data, true);
        /** @var array<string, mixed> $payload */
        $payload = is_array($decoded) ? $decoded : [];

        $declared = $payload['event_class'] ?? MigratableDummyEvent::class;
        /** @var class-string<MigratableDummyEvent> $eventClass */
        $eventClass = is_string($declared) && is_a($declared, MigratableDummyEvent::class, true)
            ? $declared
            : MigratableDummyEvent::class;

        $fromVersion = is_numeric($payload['version'] ?? null) ? (int) $payload['version'] : 1;
        $toVersion = $eventClass::getLatestSchemaVersion();
        $payload = $eventClass::migratePayload($payload, $fromVersion, $toVersion);

        foreach (['aggregateId', 'eventId', 'occurredOn', 'version', 'delta', 'description'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new InvalidArgumentException("Missing required field '$field' in payload");
            }
        }

        $aggregateId = EntityIdentifier::fromString(
            is_string($payload['aggregateId']) ? $payload['aggregateId'] : ''
        );
        $eventId = $payload['eventId'];
        $delta = $payload['delta'];
        $description = $payload['description'];
        $occurredOn = new DateTimeImmutable(is_string($payload['occurredOn']) ? $payload['occurredOn'] : 'now');
        $version = $payload['version'];

        $reflection = new ReflectionClass($eventClass);
        $event = $reflection->newInstance($aggregateId, $eventId, $delta, $description, $occurredOn, $version);

        if (!$event instanceof DomainEventInterface) {
            throw new RuntimeException('The serializer did not produce a domain event.');
        }

        return $event;
    }
}
