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
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventStream;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Exception\ConcurrencyException;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotableAggregateInterface;
use DomainFlow\EventSourcing\Interface\SnapshotFactoryInterface;
use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use DomainFlow\EventSourcing\Repository\AggregateRepository;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshot;
use DomainFlow\Uuid\UuidV6;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use ReflectionException;

abstract class AutomaticSnapshottingIntegrationTestCase extends TestCase
{
    private AggregateRepository $repository;

    abstract protected function getStorage(): EventStorageInterface;
    abstract protected function getSnapshotStorage(): SnapshotStorageInterface;
    abstract protected function getSnapshotHistoryStorage(): SnapshotHistoryStorageInterface;

    public function setUp(): void
    {
        $this->repository = new AggregateRepository(
            $this->getStorage(),
            $this->getSnapshotStorage(),
            new SnapshottingDummySnapshotFactory(),
            $this->getSnapshotHistoryStorage()
        );
    }

    /**
     * @throws ConcurrencyException|DateMalformedStringException|ReflectionException|RandomException
     */
    public function test_automaticSnapshottingCreatesSnapshotAndHistory(): void
    {
        [$aggregate, $snapshot, $history] = $this->processAggregate();

        // With 15 events with alternating delta (odd=1, even=2), total = 8*1 + 7*2 = 8 + 14 = 22.
        $this->assertEquals(22, $aggregate->getCounter()->toInt(), "Aggregate counter should be 22.");
        $this->assertNotNull($snapshot, "A snapshot should have been retrieved.");
        $this->assertEquals(
            15,
            $snapshot->getVersion()->toInt(),
            "Snapshot version is the aggregate's stream position (15 events), not its business counter (22)."
        );
        $this->assertEquals(
            ['counter' => 22],
            $snapshot->getState(),
            "Snapshot state should reflect the aggregate's full snapshot payload."
        );

        $this->assertCount(1, $history, "Snapshot history should contain one snapshot entry.");
    }

    /**
     * @throws ConcurrencyException|DateMalformedStringException|ReflectionException|RandomException
     * @return array{0: SnapshottingDummyAggregate, 1: SnapshotInterface|null, 2: SnapshotInterface[]}
     */
    private function processAggregate(): array
    {
        $aggregateId = EntityIdentifier::fromString(
            (string) UuidV6::generate()
        );

        $eventStorage = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        $existingEvents = $eventStorage->retrieveEvents($aggregateId);
        $currentCount = count($existingEvents);
        $startingVersion = $currentCount + 1;

        for ($i = $startingVersion; $i < $startingVersion + 15; $i++) {
            $eventId = EventId::generate();
            $delta = ($i % 2 === 0) ? 2 : 1;
            $event = new SnapshottingDummyEvent(
                $aggregateId,
                $eventId,
                $delta,
                new DateTimeImmutable(),
                $i
            );
            $eventStorage->storeEvents([$event]);
        }

        $retrievedEvents = $eventStorage->retrieveEvents($aggregateId);
        $entries = array_map(fn ($event) => EventEntry::fromDomainEvent($event), $retrievedEvents);
        $stream = new EventStream($entries);
        $SnapshottingDummyAggregate = SnapshottingDummyAggregate::reconstitute($stream);

        $this->repository->saveWithSnapshot($SnapshottingDummyAggregate);

        $retrievedSnapshot = $this->getSnapshotStorage()->retrieveSnapshot($aggregateId);
        $history = $this->getSnapshotHistoryStorage()->retrieveAll($aggregateId);

        return [$SnapshottingDummyAggregate, $retrievedSnapshot, $history];
    }
}

// dummy classes
final class SnapshottingDummySnapshotFactory implements SnapshotFactoryInterface
{
    public function createFromStorage(
        string $snapshotClass,
        EntityIdentifierInterface $aggregateId,
        EventVersion $version,
        array $state
    ): SnapshotInterface {
        return new GenericSnapshot($aggregateId, $version, $state, OccurredOn::now());
    }
}

final class SnapshottingDummyEvent extends SourceEvent
{
    use ReadsPayloadValues;

    private int $delta;

    public function __construct(
        EntityIdentifierInterface $aggregateId,
        EntityIdentifierInterface $eventId,
        int $delta,
        ?DateTimeImmutable $occurredOn = null,
        int $version = 1
    ) {
        $versionObject = EventVersion::fromInt($version);
        parent::__construct($aggregateId, $eventId, $occurredOn, $versionObject);
        $this->delta = $delta;
        $this->version = $versionObject;
    }

    public function getDelta(): int
    {
        return $this->delta;
    }

    public function toArray(): array
    {
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
     */
    public static function fromArray(array $data): self
    {
        $occurredOn = self::payloadString($data, 'occurredOn');

        return new self(
            EntityIdentifier::fromString(self::payloadString($data, 'aggregateId')),
            EntityIdentifier::fromString(self::payloadString($data, 'eventId')),
            self::payloadInt($data, 'delta'),
            $occurredOn === '' ? null : new DateTimeImmutable($occurredOn),
            self::payloadInt($data, 'version', 1)
        );
    }

    public static function getFactory(): callable
    {
        return [self::class, 'fromArray'];
    }

}

final class SnapshottingDummyAggregate extends AggregateRoot implements SnapshotableAggregateInterface
{
    private EventVersion $counter;
    private EntityIdentifierInterface $id;

    /**
     * @throws RandomException
     */
    public function __construct(
        ?EntityIdentifierInterface $id = null
    ) {
        $this->id = $id ?? EventId::generate();
        $this->counter = EventVersion::fromInt(0);

    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function getCounter(): EventVersion
    {
        return $this->counter;
    }

    public function applySnapshottingDummyEvent(
        SnapshottingDummyEvent $event
    ): void {
        $this->counter = $this->counter->add($event->getDelta());
    }

    public function shouldTakeSnapshot(): bool
    {
        return true;
    }

    public function getSnapshotState(): array
    {
        return ['counter' => $this->counter->toInt()];
    }

    /**
     * The aggregate's position in its own stream — deliberately not the
     * business counter, which happens to be a different number and would make
     * AggregateRepository filter the wrong events on load.
     */
    public function getSnapshotVersion(): EventVersion
    {
        return $this->getAggregateVersion();
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->id;
    }

    public function applySnapshot(
        SnapshotInterface $snapshot
    ): void {
        $counter = $snapshot->getState()['counter'] ?? 0;

        $this->counter = EventVersion::fromInt(is_numeric($counter) ? (int) $counter : 0);
    }

    /**
     * @throws ReflectionException
     * @throws DateMalformedStringException
     * @throws RandomException
     */
    public static function reconstitute(
        EventStream $stream
    ): static {
        $firstEntry = iterator_to_array($stream)[0] ?? null;
        $aggregateId = $firstEntry?->toDomainEvent()->getAggregateId() ?? EntityIdentifier::fromString('unknown');
        $instance = new static($aggregateId);

        foreach ($stream as $entry) {
            $event = $entry->toDomainEvent();
            $instance->applyEvent($event, false);
        }

        return $instance;
    }

    public function getSnapshotClass(): string
    {
        return GenericSnapshot::class;
    }
}
