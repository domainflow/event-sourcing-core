<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Integration;

use DateMalformedStringException;
use DateTimeImmutable;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcingCore\Provider\Integration\DummyEventSerializer;
use DomainFlow\EventSourcingCore\Provider\Integration\EventMigrationAndSerializationIntegrationTestCase;
use DomainFlow\EventSourcingCore\Tests\Setup\InMemorySetup;
use PHPUnit\Framework\Attributes\CoversNothing;
use ReflectionException;

#[CoversNothing()]
final class EventMigrationAndSerializationIntegrationTest extends EventMigrationAndSerializationIntegrationTestCase
{
    use InMemorySetup;

    /**
     * @throws DateMalformedStringException
     */
    protected function insertEvent(
        string $eventId,
        EntityIdentifier $aggregateId,
        string $eventClass,
        int $version,
        string $occurredOn,
        array $payload
    ): void {
        $event = new $eventClass(
            $aggregateId,
            $eventId,
            $payload['delta'],
            $payload['description'],
            new DateTimeImmutable($occurredOn),
            $version
        );

        $this->getStorage()->storeEvents([$event]);
    }

    /**
     * @throws DateMalformedStringException|ReflectionException
     */
    protected function insertLegacyEvent(
        EntityIdentifier $aggregateId,
        string $eventId,
        string $occurredOn
    ): void {
        $legacyPayload = [
            'aggregateId' => (string) $aggregateId,
            'eventId' => $eventId,
            'occurredOn' => $occurredOn,
            'version' => 1,
            'delta' => 3,
            // No 'description' key
        ];

        $json = json_encode($legacyPayload);
        $serializer = new DummyEventSerializer();
        $event = $serializer->deserialize($json);

        $this->getStorage()->storeEvents([$event]);
    }

}
