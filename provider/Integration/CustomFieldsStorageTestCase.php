<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Integration;

use DateMalformedStringException;
use DateTimeImmutable;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventPersistenceRecord;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventEntryFactoryInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Repository\AggregateRepository;
use DomainFlow\Uuid\UuidV6;
use JsonException;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use RuntimeException;

abstract class CustomFieldsStorageTestCase extends TestCase
{
    abstract protected function getStorageWithFactory(EventEntryFactoryInterface $factory): EventStorageInterface;

    /**
     * @throws RandomException
     */
    public function test_it_stores_and_loads_custom_event_with_custom_fields(): void
    {
        $factory = new CustomEventEntryFactory();
        $storage = $this->getStorageWithFactory($factory);
        $repo = new AggregateRepository($storage);

        $aggregateId = EntityIdentifier::fromString('agg-login-001');
        $agg = new CustomAggregate();

        $agg->applyEvent(new CustomEvent(
            $aggregateId,
            EventId::generate(),
            'user-987',
            '10.20.30.40',
            'mobile',
            true,
            'password',
            'Berlin, DE',
            OccurredOn::now(),
            EventVersion::fromInt(1)
        ));

        $repo->save($agg);

        $events = $storage->retrieveEvents($aggregateId);
        $this->assertCount(1, $events);

        /** @var CustomEvent $event */
        $event = $events[0];

        $this->assertSame('user-987', $event->getUserId());
        $this->assertSame('10.20.30.40', $event->getIp());
        $this->assertSame('mobile', $event->getDeviceType());

        $this->assertTrue($event->wasSuccessful());
        $this->assertSame('password', $event->getAuthMethod());
        $this->assertSame('Berlin, DE', $event->getGeoLocation());
    }

    /**
     * @throws RandomException
     */
    public function test_it_reconstitutes_state_across_multiple_events(): void
    {
        $factory = new CustomEventEntryFactory();
        $storage = $this->getStorageWithFactory($factory);
        $repo = new AggregateRepository($storage);

        $aggregateId = EntityIdentifier::fromString((string) UuidV6::generate());
        $agg = new StatefulAggregate();

        $agg->applyEvent(new CustomEvent(
            $aggregateId,
            EventId::generate(),
            'user-xyz',
            '10.0.0.1',
            'desktop',
            true,
            'password',
            'Paris',
            OccurredOn::now(),
            EventVersion::fromInt(1)
        ));

        $agg->applyEvent(new CustomEvent(
            $aggregateId,
            EventId::generate(),
            'user-xyz',
            '172.16.5.10',
            'mobile',
            true,
            'oauth',
            'London',
            OccurredOn::now(),
            EventVersion::fromInt(2)
        ));

        $agg->applyEvent(new CustomEvent(
            $aggregateId,
            EventId::generate(),
            'user-xyz',
            '192.168.1.22',
            'tablet',
            false,
            'fingerprint',
            'Berlin',
            OccurredOn::now(),
            EventVersion::fromInt(3)
        ));

        $repo->save($agg);
        $events = $storage->retrieveEvents($aggregateId);
        $rehydrated = StatefulAggregate::reconstituteFromEvents($events);

        $this->assertSame('192.168.1.22', $rehydrated->getLastLoginIp());
        $this->assertSame(3, $rehydrated->getLoginCount());
        $this->assertSame(3, $rehydrated->getVersion()->toInt());
        $this->assertSame('Berlin', $rehydrated->getLastLoginLocation());

        $rehydrated->applyEvent(new CustomEvent(
            $aggregateId,
            EventId::generate(),
            'user-xyz',
            '8.8.8.8',
            'laptop',
            true,
            'sso',
            'Dublin',
            OccurredOn::now(),
            EventVersion::fromInt(4)
        ));

        $rehydrated->applyEvent(new CustomEvent(
            $aggregateId,
            EventId::generate(),
            'user-xyz',
            '172.31.0.4',
            'phone',
            false,
            'magiclink',
            'Barcelona',
            OccurredOn::now(),
            EventVersion::fromInt(5)
        ));

        $repo->save($rehydrated);
        $allEvents = $storage->retrieveEvents($aggregateId);
        $rehydrated = StatefulAggregate::reconstituteFromEvents($allEvents);

        $lastEvent = end($allEvents);
        $this->assertInstanceOf(DomainEventInterface::class, $lastEvent);
        $this->assertSame(5, $lastEvent->getVersion()->toInt());
        $this->assertSame(5, $rehydrated->getVersion()->toInt());
    }

}

final class CustomAggregate extends AggregateRoot
{
    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function applyCustomEvent(
        CustomEvent $event
    ): void {
    }
}

final class CustomEvent extends SourceEvent
{
    use ReadsPayloadValues;

    public function __construct(
        EntityIdentifierInterface $aggregateId,
        EntityIdentifierInterface $eventId,
        private readonly string $userId,
        private readonly string $ip,
        private readonly string $deviceType,
        private readonly bool $wasSuccessful,
        private readonly string $authMethod,
        private readonly string $geoLocation,
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
    }

    public function toArray(): array
    {
        return [
            'wasSuccessful' => $this->wasSuccessful,
            'authMethod' => $this->authMethod,
            'geoLocation' => $this->geoLocation,
        ];
    }

    /**
     * @throws DateMalformedStringException|RandomException
     */
    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(
        array $data
    ): DomainEventInterface {
        $raw = $data['payload'] ?? '{}';

        if (is_string($raw)) {
            $once = json_decode($raw, true);
            $payload = is_string($once) ? json_decode($once, true) : $once;
        } elseif (is_array($raw)) {
            $payload = $raw;
        } else {
            $payload = [];
        }

        /** @var array<string, mixed> $payload */
        $eventId = self::payloadString($data, 'event_id');

        return new self(
            EntityIdentifier::fromString(self::payloadString($data, 'aggregate_id')),
            $eventId === '' ? EventId::generate() : EventId::fromString($eventId),
            self::payloadString($data, 'userId'),
            self::payloadString($data, 'ip'),
            self::payloadString($data, 'deviceType'),
            self::payloadBool($payload, 'wasSuccessful'),
            self::payloadString($payload, 'authMethod', 'unknown'),
            self::payloadString($payload, 'geoLocation', 'unknown'),
            new DateTimeImmutable(self::payloadString($data, 'occurred_on', 'now')),
            EventVersion::fromInt(self::payloadInt($data, 'version', 1))
        );

    }

    /**
     * @return array<string, mixed>
     */
    public function getDatabaseFields(): array
    {
        return [
            'userId' => $this->userId,
            'ip' => $this->ip,
            'deviceType' => $this->deviceType,
        ];
    }

    public static function getFactory(): callable
    {
        return [self::class, 'fromArray'];
    }

    public function getEventId(): EntityIdentifierInterface
    {
        return $this->eventId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getDeviceType(): string
    {
        return $this->deviceType;
    }

    public function wasSuccessful(): bool
    {
        return $this->wasSuccessful;
    }

    public function getAuthMethod(): string
    {
        return $this->authMethod;
    }

    public function getGeoLocation(): string
    {
        return $this->geoLocation;
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }
}

final class CustomEventEntryFactory implements EventEntryFactoryInterface
{
    /**
     * @throws JsonException
     */
    public function createFromDomainEvent(
        DomainEventInterface $event
    ): EventPersistenceRecord {
        // This factory exists for CustomEvent, which is the only event with
        // getDatabaseFields()/getEventId() — the interface has neither.
        if (!$event instanceof CustomEvent) {
            throw new RuntimeException(sprintf('%s only handles %s.', self::class, CustomEvent::class));
        }

        return new EventPersistenceRecord(
            $event->getDatabaseFields() + [
                'aggregate_id' => (string) $event->getAggregateId(),
                'event_id' => (string) $event->getEventId(),
                'event_class' => get_class($event),
                'payload' => json_encode($event->toArray(), JSON_THROW_ON_ERROR),
                'occurred_on' => $event->getOccurredOn()->format('Y-m-d H:i:s.u'),
                'version' => $event->getVersion()->toInt(),
            ]
        );
    }

    public function recordToDomainEvent(
        EventPersistenceRecord $record
    ): DomainEventInterface {
        return CustomEvent::fromArray($record->toArray());
    }

    /**
     * @param array<string, mixed> $row
     */
    public function recordFromArray(
        array $row
    ): EventPersistenceRecord {
        return EventPersistenceRecord::fromArray($row);
    }

    /**
     * @return array<string, string>
     */
    public function getFieldDefinitions(): array
    {
        return [
            'userId' => 'VARCHAR(100)',
            'ip' => 'VARCHAR(45)',
            'deviceType' => 'VARCHAR(100)',
        ];
    }
}

final class StatefulAggregate extends AggregateRoot
{
    private ?string $lastLoginIp = null;
    private ?string $lastLoginLocation = null;
    private int $loginCount = 0;

    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function applyCustomEvent(
        CustomEvent $event
    ): void {
        $this->lastLoginIp = $event->getIp();
        $this->lastLoginLocation = $event->getGeoLocation();
        $this->loginCount++;
    }

    public function getLastLoginIp(): ?string
    {
        return $this->lastLoginIp;
    }

    public function getLastLoginLocation(): ?string
    {
        return $this->lastLoginLocation;
    }

    public function getLoginCount(): int
    {
        return $this->loginCount;
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    /**
     * @param array<DomainEventInterface> $events
     */
    public static function reconstituteFromEvents(
        array $events
    ): self {
        $instance = new self();
        foreach ($events as $event) {
            $instance->applyEvent($event, false);
            $instance->version = $event->getVersion();
        }

        return $instance;
    }
}
