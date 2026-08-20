<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Integration;

use DateMalformedStringException;
use DateTimeImmutable;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotableAggregateInterface;
use DomainFlow\EventSourcing\Interface\SnapshotFactoryInterface;
use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use DomainFlow\EventSourcing\Repository\AggregateRepository;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshot;
use PHPUnit\Framework\TestCase;
use ReflectionException;

abstract class DummyAggregateIntegrationTestCase extends TestCase
{
    protected AggregateRepository $repository;

    abstract protected function getStorage(): EventStorageInterface;
    abstract protected function getSnapshotStorage(): SnapshotStorageInterface;
    abstract protected function getSnapshotHistoryStorage(): SnapshotHistoryStorageInterface;

    private SnapshotStorageInterface $snapshotStorage;

    protected function setUp(): void
    {
        $this->repository = new AggregateRepository(
            $this->getStorage(),
            $this->getSnapshotStorage(),
            new DummySnapshotFactory(),
            $this->getSnapshotHistoryStorage()
        );

        $this->snapshotStorage = $this->getSnapshotStorage();
    }

    /**
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_counter_updates_and_snapshot_is_persisted(): void
    {
        $aggregateId = EntityIdentifier::fromString('agg-1');
        $aggregate = new DummyAggregate();

        $expectedCounter = 0;

        for ($i = 1; $i <= 10; $i++) {
            $delta = ($i % 2 === 0) ? 2 : 1;
            $aggregate->applyEvent(
                new DummyEvent($aggregateId, uniqid('ev_', true), $delta, null, $i)
            );
            $expectedCounter += $delta;
        }

        $this->assertCount(10, $aggregate->getUncommittedEvents());
        $this->assertEquals(10, $aggregate->getSnapshotVersion()->toInt());
        $this->assertEquals(['counter' => $expectedCounter], $aggregate->getSnapshotState());

        $this->repository->saveWithSnapshot(
            $aggregate,
            new GenericSnapshot(
                $aggregateId,
                EventVersion::fromInt(10),
                ['counter' => $expectedCounter],
                OccurredOn::now()
            )
        );

        $snapshot = $this->snapshotStorage->retrieveSnapshot($aggregateId);

        $this->assertInstanceOf(DummyAggregate::class, $aggregate);
        $this->assertSame(15, $aggregate->getCounter());
        $this->assertSame('agg-1', (string) $aggregateId);
        $this->assertInstanceOf(SnapshotInterface::class, $snapshot);
        $this->assertEquals(['counter' => $expectedCounter], $snapshot->getState());

        $reloaded = $this->repository->load(DummyAggregate::class, $aggregateId);

        $this->assertInstanceOf(DummyAggregate::class, $reloaded);
        $this->assertSame($expectedCounter, $reloaded->getCounter());
    }
}

// dummy classes
final class DummySnapshotFactory implements SnapshotFactoryInterface
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

final class DummyEvent extends SourceEvent
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

final class DummyAggregate extends AggregateRoot implements SnapshotableAggregateInterface
{
    private int $counter = 0;

    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function applyDummyEvent(
        DummyEvent $event
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

    /**
     * The aggregate's position in its own stream, not the business counter.
     */
    public function getSnapshotVersion(): EventVersion
    {
        return $this->getAggregateVersion();
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString('dummy-aggregate');
    }

    public function applySnapshot(
        SnapshotInterface $snapshot
    ): void {
        $counter = $snapshot->getState()['counter'] ?? 0;
        $this->counter = is_numeric($counter) ? (int) $counter : 0;
    }

}
