<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Integration;

use DateMalformedStringException;
use DateTimeImmutable;
use DomainFlow\EventSourcing\Concurrency\ConcurrencyCheckingStorage;
use DomainFlow\EventSourcing\Concurrency\MaxVersionStrategy;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Exception\ConcurrencyException;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use DomainFlow\Uuid\UuidV6;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

abstract class ConcurrencyIntegrationTestCase extends TestCase
{
    private ConcurrencyCheckingStorage $eventStorage;
    abstract protected function getStorage(): EventStorageInterface;
    abstract protected function getSnapshotStorage(): SnapshotStorageInterface;
    abstract protected function getSnapshotHistoryStorage(): SnapshotHistoryStorageInterface;

    protected function setUp(): void
    {
        $this->eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());
    }

    /**
     * @throws ConcurrencyException|RandomException
     */
    public function test_concurrencyCheck(): void
    {
        $aggregateId = EntityIdentifier::fromString((string) UuidV6::generate());

        $event1 = new YetAnotherDummyEvent(
            $aggregateId,
            EventId::generate(),
            1,
            new DateTimeImmutable(),
            EventVersion::fromInt(1)
        );
        $this->eventStorage->storeEvents([$event1]);

        $event2 = new YetAnotherDummyEvent(
            $aggregateId,
            EventId::generate(),
            2,
            new DateTimeImmutable(),
            EventVersion::fromInt(2)
        );
        $this->eventStorage->storeEvents([$event2]);

        $conflictingEvent = new YetAnotherDummyEvent(
            $aggregateId,
            EventId::generate(),
            3,
            new DateTimeImmutable(),
            EventVersion::fromInt(2)
        );

        $this->expectException(ConcurrencyException::class);
        $this->expectExceptionMessage('Concurrency conflict: expected version 3, got 2');
        $this->eventStorage->storeEvents([$conflictingEvent]);

        $this->fail('Expected ConcurrencyException was not thrown.');
    }

    /**
     * @throws ConcurrencyException|RandomException
     */
    public function test_skippedVersionCausesConflict(): void
    {
        $aggregateId = EntityIdentifier::fromString((string) UuidV6::generate());

        $event1 = new YetAnotherDummyEvent(
            $aggregateId,
            EventId::generate(),
            10,
            new DateTimeImmutable(),
            EventVersion::fromInt(1)
        );
        $this->eventStorage->storeEvents([$event1]);

        $badEvent = new YetAnotherDummyEvent(
            $aggregateId,
            EventId::generate(),
            20,
            new DateTimeImmutable(),
            EventVersion::fromInt(5)
        );

        $this->expectException(ConcurrencyException::class);
        $this->expectExceptionMessage('expected version 2, got 5');
        $this->eventStorage->storeEvents([$badEvent]);
    }

    /**
     * @throws ConcurrencyException|RandomException
     */
    public function testMultipleAggregatesDontInterfere(): void
    {
        $idA = EntityIdentifier::fromString((string) UuidV6::generate());
        $evtA1 = new YetAnotherDummyEvent(
            $idA,
            EventId::generate(),
            100,
            new DateTimeImmutable(),
            EventVersion::fromInt(1)
        );
        $evtA2 = new YetAnotherDummyEvent(
            $idA,
            EventId::generate(),
            200,
            new DateTimeImmutable(),
            EventVersion::fromInt(2)
        );
        $this->eventStorage->storeEvents([$evtA1]);
        $this->eventStorage->storeEvents([$evtA2]);

        $idB = EntityIdentifier::fromString((string) UuidV6::generate());
        $evtB1 = new YetAnotherDummyEvent(
            $idB,
            EventId::generate(),
            300,
            new DateTimeImmutable(),
            EventVersion::fromInt(1)
        );
        $evtB2 = new YetAnotherDummyEvent(
            $idB,
            EventId::generate(),
            400,
            new DateTimeImmutable(),
            EventVersion::fromInt(2)
        );
        $this->eventStorage->storeEvents([$evtB1]);
        $this->eventStorage->storeEvents([$evtB2]);

        // Reaching this line is the assertion: a clash would have thrown.
        $this->assertCount(2, $this->getStorage()->retrieveEvents($evtB1->getAggregateId()), 'Separate aggregates must not conflict with each other.');
    }

    /**
     * @throws ConcurrencyException|RandomException
     */
    public function test_duplicateFirstVersionOnNewAggregateFails(): void
    {
        $id = EntityIdentifier::fromString((string) UuidV6::generate());

        $first = new YetAnotherDummyEvent(
            $id,
            EventId::generate(),
            10,
            new DateTimeImmutable(),
            EventVersion::fromInt(1)
        );
        $this->eventStorage->storeEvents([$first]);

        $duplicate = new YetAnotherDummyEvent(
            $id,
            EventId::generate(),
            20,
            new DateTimeImmutable(),
            EventVersion::fromInt(1)
        );

        $this->expectException(ConcurrencyException::class);
        $this->expectExceptionMessage('Concurrency conflict: expected version 2, got 1');

        $this->eventStorage->storeEvents([$duplicate]);
    }

}

// dummy class
final class YetAnotherDummyEvent extends SourceEvent
{
    use ReadsPayloadValues;

    private int $delta;

    public function __construct(
        EntityIdentifierInterface $aggregateId,
        EntityIdentifierInterface $eventId,
        int $delta,
        DateTimeImmutable $occurredOn,
        EventVersion|int $version
    ) {
        $versionObject = $version instanceof EventVersion ? $version : EventVersion::fromInt($version);
        parent::__construct($aggregateId, $eventId, $occurredOn, $versionObject);
        $this->delta = $delta;
    }

    public function getDelta(): int
    {
        return $this->delta;
    }

    /**
     * @throws DateMalformedStringException
     */
    public function toArray(): array
    {
        // Keys must match the constructor parameter names exactly: when
        // EventEntry::$factory is set to a ReflectionEventFactory (which
        // happens process-wide once any test configures it), events are
        // reconstructed via reflection against these payload keys instead
        // of via this class's own getFactory()/fromArray().
        return [
            'aggregateId' => (string) $this->getAggregateId(),
            'eventId' => (string) $this->eventId,
            'delta' => $this->delta,
            'occurredOn' => $this->getOccurredOn()->format(DATE_ATOM),
            'version' => $this->getVersion()->toInt(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @throws DateMalformedStringException|RandomException
     */
    public static function fromArray(
        array $data
    ): self {
        return new self(
            EntityIdentifier::fromString(self::payloadString($data, 'aggregateId')),
            EventId::fromString(self::payloadString($data, 'eventId')),
            self::payloadInt($data, 'delta'),
            new DateTimeImmutable(self::payloadString($data, 'occurredOn', 'now')),
            EventVersion::fromInt(self::payloadInt($data, 'version'))
        );
    }

    public static function getFactory(): callable
    {
        return [self::class, 'fromArray'];
    }
}
