<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Facade;

use DateMalformedStringException;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Concurrency\ConcurrencyCheckingStorage;
use DomainFlow\EventSourcing\Concurrency\ConcurrencyCheckStrategyInterface;
use DomainFlow\EventSourcing\Event\MetadataEnricher;
use DomainFlow\EventSourcing\Exception\DoubleDeliveryException;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventDispatcherInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\OutboxBackedStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotableAggregateInterface;
use DomainFlow\EventSourcing\Interface\SnapshotFactoryInterface;
use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use DomainFlow\EventSourcing\Repository\AggregateRepository;
use Exception;
use ReflectionException;

final class EventSourcingFacade
{
    private EventStorageInterface $eventStorage;
    private ?SnapshotStorageInterface $snapshotStorage;
    private ?SnapshotFactoryInterface $snapshotFactory;
    private ?SnapshotHistoryStorageInterface $snapshotHistory;
    private readonly ?EventDispatcherInterface $dispatcher;
    private readonly ?MetadataEnricher $metadataEnricher;

    private AggregateRepository $repository;

    /**
     * @param EventStorageInterface $eventStorage
     * @param SnapshotStorageInterface|null $snapshotStorage
     * @param SnapshotFactoryInterface|null $snapshotFactory
     * @param SnapshotHistoryStorageInterface|null $snapshotHistory
     * @param EventDispatcherInterface|null $dispatcher Delivers inline, in the
     *        writing process. Leave it out when an outbox relay is delivering —
     *        the two are not exclusive, and together every event goes out
     *        twice. A storage that says it is outbox-backed makes that
     *        combination an error here rather than a duplicate at the consumer.
     * @param MetadataEnricher|null $metadataEnricher
     * @throws DoubleDeliveryException
     */
    public function __construct(
        EventStorageInterface $eventStorage,
        ?SnapshotStorageInterface $snapshotStorage = null,
        ?SnapshotFactoryInterface $snapshotFactory = null,
        ?SnapshotHistoryStorageInterface $snapshotHistory = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?MetadataEnricher $metadataEnricher = null,
    ) {
        $this->assertOneDeliveryPath($eventStorage, $dispatcher);

        $this->eventStorage = $eventStorage;
        $this->snapshotStorage = $snapshotStorage;
        $this->snapshotFactory = $snapshotFactory;
        $this->snapshotHistory = $snapshotHistory;
        $this->dispatcher = $dispatcher;
        $this->metadataEnricher = $metadataEnricher;

        $this->repository = $this->buildRepository();
    }

    private function buildRepository(): AggregateRepository
    {
        return new AggregateRepository(
            $this->eventStorage,
            $this->snapshotStorage,
            $this->snapshotFactory,
            $this->snapshotHistory,
            $this->metadataEnricher,
        );
    }

    /**
     * Refuse the one arrangement that delivers everything twice.
     *
     * `persist()` dispatches inline and a relay dispatches out of band; both at
     * once is a duplicate on every write, with nothing failing at the writer
     * and the duplicate arriving at the consumer. Adopting the outbox is a
     * *subtraction* — the dispatcher is already there from the arrangement
     * before it — which is exactly the kind of step that gets left out.
     *
     * A storage that says nothing is taken at its word and left alone, so an
     * adapter outside these four repositories is unaffected. **The hole this
     * leaves is an out-of-tree decorator**: it hides the capability of the
     * storage it wraps, and there is no way from here to see through one.
     * `ConcurrencyCheckingStorage` forwards it for that reason.
     *
     * @param EventStorageInterface $eventStorage
     * @param EventDispatcherInterface|null $dispatcher
     * @throws DoubleDeliveryException
     * @return void
     */
    private function assertOneDeliveryPath(
        EventStorageInterface $eventStorage,
        ?EventDispatcherInterface $dispatcher
    ): void {
        if ($dispatcher === null) {
            return;
        }

        if (!$eventStorage instanceof OutboxBackedStorageInterface || !$eventStorage->deliversThroughOutbox()) {
            return;
        }

        throw new DoubleDeliveryException(
            'This event storage hands its writes to an outbox, so a relay delivers them. '
            . 'Passing a dispatcher as well delivers every event twice: once inline here and '
            . 'once from the relay. Drop the dispatcher, or drop the outbox.'
        );
    }

    /**
     * Enable concurrency checking for the event storage.
     *
     * @param ConcurrencyCheckStrategyInterface $strategy
     * @return void
     */
    public function enableConcurrencyCheck(
        ConcurrencyCheckStrategyInterface $strategy
    ): void {
        $this->eventStorage = new ConcurrencyCheckingStorage($this->eventStorage, $strategy);

        $this->repository = $this->buildRepository();
    }

    /**
     * Loads an aggregate instance by ID.
     *
     * @template T of AggregateRoot
     * @param class-string<T> $aggregateClass
     * @param EntityIdentifierInterface $aggregateId
     * @throws DateMalformedStringException|ReflectionException
     * @return AggregateRoot
     */
    public function load(
        string $aggregateClass,
        EntityIdentifierInterface $aggregateId
    ): AggregateRoot {
        return $this->repository->load($aggregateClass, $aggregateId);
    }

    /**
     * Saves the aggregate and optionally triggers snapshot logic.
     *
     * @param AggregateRoot $aggregate
     * @return void
     */
    public function persist(
        AggregateRoot $aggregate
    ): void {
        // The events as stored, not as emitted: metadata is attached on the
        // way in, and dispatching the originals would hand subscribers events
        // the store does not have.
        $stored = $this->repository->saveWithSnapshot($aggregate);
        $this->dispatcher?->dispatchAll($stored);

    }

    /**
     * Loads, mutates, and persists an aggregate in one step.
     *
     * @template T of AggregateRoot
     * @param class-string<T> $aggregateClass
     * @param EntityIdentifierInterface $aggregateId
     * @param callable(AggregateRoot): void $callback
     * @throws Exception
     */
    public function apply(
        string $aggregateClass,
        EntityIdentifierInterface $aggregateId,
        callable $callback
    ): void {
        $aggregate = $this->load($aggregateClass, $aggregateId);
        $callback($aggregate);
        $this->persist($aggregate);
    }

    /**
     * Deletes all events + snapshot data for an aggregate.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return void
     */
    public function delete(
        EntityIdentifierInterface $aggregateId
    ): void {
        $this->repository->delete($aggregateId);
    }

    /**
     * Manually create and store a snapshot.
     *
     * @param AggregateRoot $aggregate
     * @return SnapshotInterface|null
     */
    public function createAndPersistSnapshot(
        AggregateRoot $aggregate
    ): ?SnapshotInterface {
        if (!$aggregate instanceof SnapshotableAggregateInterface) {
            return null;
        }

        $snapshotClass = $aggregate->getSnapshotClass();
        $snapshot = $this->repository->createSnapshot($aggregate, $snapshotClass);
        if ($snapshot !== null) {
            $this->repository->saveWithSnapshot($aggregate, $snapshot);
        }

        return $snapshot;
    }

    /**
     * Replay events for an aggregate (optional utility).
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return array<array-key, DomainEventInterface>
     */
    public function replay(
        EntityIdentifierInterface $aggregateId
    ): array {
        return $this->eventStorage->retrieveEvents(
            $aggregateId
        );
    }
}
