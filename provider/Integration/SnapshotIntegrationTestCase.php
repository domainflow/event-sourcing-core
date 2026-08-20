<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Integration;

use DateMalformedStringException;
use DateTimeImmutable;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Concurrency\ConcurrencyCheckingStorage;
use DomainFlow\EventSourcing\Concurrency\MaxVersionStrategy;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventStream;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Exception\ConcurrencyException;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotableAggregateInterface;
use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshot;
use DomainFlow\Uuid\UuidV6;
use PHPUnit\Framework\TestCase;
use ReflectionException;

abstract class SnapshotIntegrationTestCase extends TestCase
{
    abstract protected function getStorage(): EventStorageInterface;
    abstract protected function getSnapshotStorage(): SnapshotStorageInterface;
    abstract protected function getSnapshotHistoryStorage(): SnapshotHistoryStorageInterface;

    /**
     * @throws ConcurrencyException|DateMalformedStringException|ReflectionException
     */
    public function test_snapshotCreationForSingleAggregate(): void
    {
        $result = $this->processAggregate('aggregate-1', 5);

        /** @var AnotherDummyAggregate $aggregate */
        $aggregate = $result['aggregate'];
        $snapshot = $result['snapshot'];
        $history = $result['history'];

        $this->assertSame(7, $aggregate->getCounter(), "Aggregate counter should be 7.");
        $this->assertNotNull($snapshot, "A snapshot should have been retrieved.");
        $this->assertSame(7, $snapshot->getVersion()->toInt(), "Snapshot version should equal aggregate counter (7).");
        $this->assertEquals(['counter' => 7], $snapshot->getState(), "Snapshot state should reflect the aggregate's counter.");
        $this->assertCount(1, $history, "Snapshot history should contain one snapshot entry.");
    }

    /**
     * @throws ConcurrencyException|DateMalformedStringException|ReflectionException
     */
    public function test_snapshotCreationForMultipleAggregates(): void
    {
        $result1 = $this->processAggregate('aggregate-1', 5);
        $result2 = $this->processAggregate('aggregate-2', 7);

        $aggregate1 = $result1['aggregate'];
        $snapshot1 = $result1['snapshot'];
        $history1 = $result1['history'];

        $aggregate2 = $result2['aggregate'];
        $snapshot2 = $result2['snapshot'];
        $history2 = $result2['history'];

        $this->assertSame(7, $aggregate1->getCounter(), "Aggregate-1 counter should be 7.");
        $this->assertNotNull($snapshot1, "Aggregate-1 snapshot should not be null.");
        $this->assertSame(7, $snapshot1->getVersion()->toInt(), "Aggregate-1 snapshot version should be 7.");
        $this->assertEquals(['counter' => 7], $snapshot1->getState(), "Aggregate-1 snapshot state should be correct.");
        $this->assertCount(1, $history1, "Aggregate-1 snapshot history should contain one entry.");

        $this->assertSame(10, $aggregate2->getCounter(), "Aggregate-2 counter should be 10.");
        $this->assertNotNull($snapshot2, "Aggregate-2 snapshot should not be null.");
        $this->assertSame(10, $snapshot2->getVersion()->toInt(), "Aggregate-2 snapshot version should be 10.");
        $this->assertEquals(['counter' => 10], $snapshot2->getState(), "Aggregate-2 snapshot state should be correct.");
        $this->assertCount(1, $history2, "Aggregate-2 snapshot history should contain one entry.");
    }

    /**
     * @throws ConcurrencyException|DateMalformedStringException|ReflectionException
     */
    public function test_snapshotHistoryAccumulatesMultipleSnapshots(): void
    {
        $this->processAggregate('aggregate-3', 5);
        $this->processAggregate('aggregate-3', 2);

        $history = $this->getSnapshotHistoryStorage()->retrieveAll(EntityIdentifier::fromString('aggregate-3'));

        $this->assertCount(2, $history, "Snapshot history for aggregate-3 should contain two entries.");
        $this->assertSame(7, $history[0]->getVersion()->toInt(), "First snapshot version should be 7.");
        $this->assertSame(10, $history[1]->getVersion()->toInt(), "Second snapshot version should be 10.");
    }

    /**
     * @param string $aggregateIdString
     * @param int $numberOfNewEvents
     * @throws ConcurrencyException|DateMalformedStringException|ReflectionException
     * @return array{aggregate: AnotherDummyAggregate, snapshot: SnapshotInterface|null, history: array<SnapshotInterface>}
     */
    private function processAggregate(
        string $aggregateIdString,
        int $numberOfNewEvents
    ): array {
        $aggregateId = EntityIdentifier::fromString($aggregateIdString);

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $existingEvents = $eventStorage->retrieveEvents($aggregateId);
        $currentCount = count($existingEvents);
        $startingVersion = $currentCount + 1;

        for ($i = $startingVersion; $i < $startingVersion + $numberOfNewEvents; $i++) {
            $eventId = (string) UuidV6::generate();

            $delta = ($i % 2 === 0) ? 2 : 1;
            $event = new ThirdDummyEvent($aggregateId, $eventId, $delta, new DateTimeImmutable(), $i);
            $eventStorage->storeEvents([$event]);
        }

        $retrievedEvents = $eventStorage->retrieveEvents($aggregateId);
        $entries = array_map(fn ($event) => EventEntry::fromDomainEvent($event), $retrievedEvents);
        $stream = new EventStream($entries);
        $AnotherDummyAggregate = AnotherDummyAggregate::reconstitute($stream);

        $snapshot = new GenericSnapshot(
            $aggregateId,
            $AnotherDummyAggregate->getSnapshotVersion(),
            $AnotherDummyAggregate->getSnapshotState(),
            OccurredOn::now()
        );

        $this->getSnapshotStorage()->storeSnapshot($snapshot);
        $this->getSnapshotHistoryStorage()->persistVersioned($snapshot);

        $retrievedSnapshot = $this->getSnapshotStorage()->retrieveSnapshot($aggregateId);
        $history = $this->getSnapshotHistoryStorage()->retrieveAll($aggregateId);

        return [
            'aggregate' => $AnotherDummyAggregate,
            'snapshot' => $retrievedSnapshot,
            'history' => $history,
        ];
    }
}

// dummy classes
final class ThirdDummyEvent extends SourceEvent
{
    private int $delta;

    public function __construct(
        EntityIdentifierInterface $aggregateId,
        string $eventId,
        int $delta,
        ?DateTimeImmutable $occurredOn = null,
        int $version = 1
    ) {
        parent::__construct($aggregateId, EntityIdentifier::fromString($eventId), $occurredOn, EventVersion::fromInt($version));
        $this->delta = $delta;
    }

    public function getDelta(): int
    {
        return $this->delta;
    }

    public function toArray(): array
    {
        $base = parent::toArray();
        $base['delta'] = $this->delta;

        return $base;
    }
}

final class AnotherDummyAggregate extends AggregateRoot implements SnapshotableAggregateInterface
{
    private int $counter = 0;

    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function applyThirdDummyEvent(
        ThirdDummyEvent $event
    ): void {
        $this->counter += $event->getDelta();
    }

    public function getCounter(): int
    {
        return $this->counter;
    }

    public function shouldTakeSnapshot(): bool
    {
        return true;
    }

    public function getSnapshotClass(): string
    {
        return GenericSnapshot::class;
    }

    public function getSnapshotState(): array
    {
        return ['counter' => $this->counter];
    }

    public function getSnapshotVersion(): EventVersion
    {
        return EventVersion::fromInt($this->counter);
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString('another-dummy');
    }

    public function applySnapshot(
        SnapshotInterface $snapshot
    ): void {
        $counter = $snapshot->getState()['counter'] ?? 0;
        $this->counter = is_numeric($counter) ? (int) $counter : 0;
    }

    public static function reconstitute(
        EventStream $stream
    ): static {
        $instance = new static();
        foreach ($stream as $entry) {
            $event = $entry->toDomainEvent();
            $instance->applyEvent($event, false);
        }

        return $instance;
    }
}
