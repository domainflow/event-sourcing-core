<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Integration;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Concurrency\ConcurrencyCheckingStorage;
use DomainFlow\EventSourcing\Concurrency\MaxVersionStrategy;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Exception\ConcurrencyException;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotableAggregateInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use DomainFlow\EventSourcing\Repository\AggregateRepository;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshot;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

abstract class ConcurrencyAttributeIntegrationTestCase extends TestCase
{
    private AggregateRepository $repository;

    abstract protected function getStorage(): EventStorageInterface;
    abstract protected function getSnapshotStorage(): SnapshotStorageInterface;

    protected function setUp(): void
    {
        $maxVersionStrategy = new MaxVersionStrategy();
        $concurrencyAwareStorage = new ConcurrencyCheckingStorage(
            $this->getStorage(),
            $maxVersionStrategy
        );

        $this->repository = new AggregateRepository(
            $concurrencyAwareStorage,
            $this->getSnapshotStorage()
        );
    }

    /**
     * @throws RandomException
     */
    public function test_concurrencyCheckPassesWithSequentialVersions(): void
    {
        $aggregate = new ConcurrencyTestAggregate();

        $aggregate->applyEvent(
            new SomeConcurrencyEvent(
                EntityIdentifier::fromString('concurrent-123'),
                EventId::generate(),
                EventVersion::fromInt(1)
            )
        );
        $this->repository->save($aggregate);

        $aggregate->clearUncommittedEvents();

        $aggregate->applyEvent(
            new SomeConcurrencyEvent(
                EntityIdentifier::fromString('concurrent-123'),
                EventId::generate(),
                EventVersion::fromInt(2)
            )
        );
        $this->repository->save($aggregate);

        // Reaching this line is the assertion: save() throws on a version
        // clash, so consecutive versions getting here means no clash.
        $this->assertSame(2, $aggregate->getAggregateVersion()->toInt(), 'Consecutive versions must not conflict.');
    }

    /**
     * @throws RandomException
     */
    public function test_concurrencyCheckThrowsOnVersionMismatch(): void
    {
        $aggregate = new ConcurrencyTestAggregate();
        $uuid = 'concurrent-123';

        $aggregate->applyEvent(
            new SomeConcurrencyEvent(
                EntityIdentifier::fromString($uuid),
                EventId::generate(),
                EventVersion::fromInt(1)
            )
        );
        $this->repository->save($aggregate);

        $aggregate->applyEvent(
            new SomeConcurrencyEvent(
                EntityIdentifier::fromString($uuid),
                EventId::generate(),
                EventVersion::fromInt(5)
            )
        );

        $this->expectException(ConcurrencyException::class);
        $this->expectExceptionMessage('Concurrency conflict: expected version 2, got 5 for aggregate concurrent-123');

        $this->repository->save($aggregate);
    }
}

// dummy classes
final class ConcurrencyTestAggregate extends AggregateRoot implements SnapshotableAggregateInterface
{
    private EventVersion $counter;

    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function shouldTakeSnapshot(): bool
    {
        return false;
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
        return $this->counter;
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        // A fixed identifier: this fixture exists to exercise the concurrency
        // check, and it never had a way to be told which aggregate it is —
        // the property it read was never written to.
        return EntityIdentifier::fromString('concurrent-123');
    }

    public function applySnapshot(
        SnapshotInterface $snapshot
    ): void {
        $data = $snapshot->getState();
        $counter = $data['counter'] ?? null;

        $this->counter = $counter instanceof EventVersion
            ? $counter
            : EventVersion::fromInt(is_numeric($counter) ? (int) $counter : 0);

    }
}

final class SomeConcurrencyEvent extends SourceEvent
{
    private EventVersion $versionOverride;

    public function __construct(
        EntityIdentifierInterface $aggregateId,
        EntityIdentifierInterface $eventId,
        EventVersion $version,
        ?DateTimeImmutable $occurredOn = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
        $this->versionOverride = $version;
    }

    public function toArray(): array
    {
        $arr = parent::toArray();
        $arr['versionOverride'] = $this->versionOverride;

        return $arr;
    }
}
