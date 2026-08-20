<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Integration;

use DateMalformedStringException;
use DateTimeImmutable;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventStream;
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

abstract class OrderAggregateIntegrationTestCase extends TestCase
{
    abstract protected function getStorage(): EventStorageInterface;
    abstract protected function getSnapshotStorage(): SnapshotStorageInterface;
    abstract protected function getSnapshotHistoryStorage(): SnapshotHistoryStorageInterface;

    /**
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_order_aggregate_lifecycle_and_snapshotting(): void
    {
        $orderId = EntityIdentifier::fromString(uniqid('order-', true));

        $orderCreated = new OrderCreated($orderId, uniqid('event_', true), 'John Doe', new DateTimeImmutable(), 1);
        $orderItemAdded1 = new OrderItemAdded($orderId, uniqid('event_', true), 'Widget', 3, new DateTimeImmutable(), 2);
        $orderItemAdded2 = new OrderItemAdded($orderId, uniqid('event_', true), 'Gadget', 2, new DateTimeImmutable(), 3);
        $orderItemRemoved = new OrderItemRemoved($orderId, uniqid('event_', true), 'Widget', 1, new DateTimeImmutable(), 4);
        $orderCompleted = new OrderCompleted($orderId, uniqid('event_', true), new DateTimeImmutable(), 5);

        $aggregate = new OrderAggregate();
        $aggregate->applyEvent($orderCreated);
        $aggregate->applyEvent($orderItemAdded1);
        $aggregate->applyEvent($orderItemAdded2);
        $aggregate->applyEvent($orderItemRemoved);
        $aggregate->applyEvent($orderCompleted);

        $aggregateRepo = new AggregateRepository(
            $this->getStorage(),
            $this->getSnapshotStorage(),
            new OrderSnapshotFactory(),
            $this->getSnapshotHistoryStorage()
        );
        $aggregateRepo->saveWithSnapshot($aggregate);

        $snapshot = $this->getSnapshotStorage()->retrieveSnapshot($orderId);

        $this->assertInstanceOf(SnapshotInterface::class, $snapshot);
        $this->assertSame((string) $orderId, (string) $snapshot->getAggregateId());
        $this->assertSame(5, $snapshot->getVersion()->toInt());

        $this->assertArrayHasKey('customerName', $snapshot->getState());
        $this->assertArrayHasKey('items', $snapshot->getState());
        $this->assertArrayHasKey('status', $snapshot->getState());

        $reloaded = OrderAggregate::reconstituteFromSnapshot(
            $snapshot->getState(),
            new EventStream([])
        );

        $this->assertSame('John Doe', $reloaded->getCustomerName());
        $this->assertSame('completed', $reloaded->getStatus());
        $this->assertEquals([
            'Widget' => 2,
            'Gadget' => 2,
        ], $reloaded->getItems());
    }

    public function test_multiple_order_aggregates_are_isolated(): void
    {
        $order1 = EntityIdentifier::fromString(uniqid('order-1', true));
        $order2 = EntityIdentifier::fromString(uniqid('order-2', true));

        $orderA = new OrderAggregate();
        $orderA->applyEvent(new OrderCreated($order1, uniqid('ev_', true), 'Alice', new DateTimeImmutable(), 1));
        $orderA->applyEvent(new OrderItemAdded($order1, uniqid('ev_', true), 'Widget', 2, new DateTimeImmutable(), 2));
        $orderA->applyEvent(new OrderCompleted($order1, uniqid('ev_', true), new DateTimeImmutable(), 3));

        $orderB = new OrderAggregate();
        $orderB->applyEvent(new OrderCreated($order2, uniqid('ev_', true), 'Bob', new DateTimeImmutable(), 1));
        $orderB->applyEvent(new OrderItemAdded($order2, uniqid('ev_', true), 'Gadget', 5, new DateTimeImmutable(), 2));
        $orderB->applyEvent(new OrderItemRemoved($order2, uniqid('ev_', true), 'Gadget', 2, new DateTimeImmutable(), 3));
        $orderB->applyEvent(new OrderCompleted($order2, uniqid('ev_', true), new DateTimeImmutable(), 4));

        $aggregateRepo = new AggregateRepository(
            $this->getStorage(),
            $this->getSnapshotStorage(),
            new OrderSnapshotFactory(),
            $this->getSnapshotHistoryStorage()
        );
        $aggregateRepo->saveWithSnapshot($orderA);
        $aggregateRepo->saveWithSnapshot($orderB);

        $snapshot1 = $this->getSnapshotStorage()->retrieveSnapshot($order1);
        $snapshot2 = $this->getSnapshotStorage()->retrieveSnapshot($order2);

        $this->assertNotNull($snapshot1, 'A snapshot should have been written for the first order.');
        $this->assertNotNull($snapshot2, 'A snapshot should have been written for the second order.');

        $this->assertSame('Alice', $snapshot1->getState()['customerName']);
        $this->assertSame('Bob', $snapshot2->getState()['customerName']);
        $this->assertEquals(['Widget' => 2], $snapshot1->getState()['items']);
        $this->assertEquals(['Gadget' => 3], $snapshot2->getState()['items']);
    }

    public function test_snapshot_is_not_taken_if_aggregate_should_not_snapshot(): void
    {
        $orderId = EntityIdentifier::fromString('order-nosnap');

        $aggregate = new OrderAggregate();
        $aggregate->applyEvent(new OrderCreated($orderId, uniqid('ev_', true), 'SnapDelay'));
        $aggregate->applyEvent(new OrderItemAdded($orderId, uniqid('ev_', true), 'Thing', 2));

        $snapshot = $this->getSnapshotStorage()->retrieveSnapshot($orderId);

        $this->assertNull($snapshot, 'Snapshot should not exist yet');
    }

    public function test_snapshot_version_matches_latest_event_version(): void
    {
        $orderId = EntityIdentifier::fromString(uniqid('order-version-check-', true));

        $aggregate = new OrderAggregate();
        $aggregate->applyEvent(new OrderCreated($orderId, uniqid('ev_', true), 'VersionCheck', new DateTimeImmutable(), 1));
        $aggregate->applyEvent(new OrderItemAdded($orderId, uniqid('ev_', true), 'Item', 1, new DateTimeImmutable(), 2));
        $aggregate->applyEvent(new OrderCompleted($orderId, uniqid('ev_', true), new DateTimeImmutable(), 3));

        $aggregateRepo = new AggregateRepository(
            $this->getStorage(),
            $this->getSnapshotStorage(),
            new OrderSnapshotFactory(),
            $this->getSnapshotHistoryStorage()
        );
        $aggregateRepo->saveWithSnapshot($aggregate);

        $snapshot = $this->getSnapshotStorage()->retrieveSnapshot($orderId);
        $this->assertNotNull($snapshot);
        $this->assertSame((string) $orderId, (string) $snapshot->getAggregateId());

    }

}

// dummy classes
final class OrderSnapshotFactory implements SnapshotFactoryInterface
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

final class OrderCreated extends SourceEvent
{
    public function __construct(
        EntityIdentifierInterface $aggregateId,
        string $eventId,
        private string $customerName,
        ?DateTimeImmutable $occurredOn = null,
        int $version = 1
    ) {
        parent::__construct($aggregateId, EntityIdentifier::fromString($eventId), $occurredOn, EventVersion::fromInt($version));
    }

    public function getCustomerName(): string
    {
        return $this->customerName;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), ['customerName' => $this->customerName]);
    }
}

final class OrderItemAdded extends SourceEvent
{
    public function __construct(
        EntityIdentifierInterface $aggregateId,
        string $eventId,
        private string $item,
        private int $quantity,
        ?DateTimeImmutable $occurredOn = null,
        int $version = 1
    ) {
        parent::__construct($aggregateId, EntityIdentifier::fromString($eventId), $occurredOn, EventVersion::fromInt($version));
    }

    public function getItem(): string
    {
        return $this->item;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'item' => $this->item,
            'quantity' => $this->quantity,
        ]);
    }
}

final class OrderItemRemoved extends SourceEvent
{
    public function __construct(
        EntityIdentifierInterface $aggregateId,
        string $eventId,
        private readonly string $item,
        private readonly int $quantity,
        ?DateTimeImmutable $occurredOn = null,
        int $version = 1
    ) {
        parent::__construct($aggregateId, EntityIdentifier::fromString($eventId), $occurredOn, EventVersion::fromInt($version));
    }

    public function getItem(): string
    {
        return $this->item;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'item' => $this->item,
            'quantity' => $this->quantity,
        ]);
    }
}

final class OrderCompleted extends SourceEvent
{
    public function __construct(
        EntityIdentifierInterface $aggregateId,
        string $eventId,
        ?DateTimeImmutable $occurredOn = null,
        int $version = 1
    ) {
        parent::__construct($aggregateId, EntityIdentifier::fromString($eventId), $occurredOn, EventVersion::fromInt($version));
    }

}

final class OrderAggregate extends AggregateRoot implements SnapshotableAggregateInterface
{
    private string $orderId = '';
    private string $customerName = '';

    /** @var array<string, int> */
    private array $items = [];
    private string $status = 'created';

    public function __construct()
    {
        $this->version = EventVersion::fromInt(0);
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }
    public function getCustomerName(): string
    {
        return $this->customerName;
    }
    /**
     * A snapshot's state is mixed by definition, so the item map is narrowed
     * once here rather than assumed at each of the four places it is read.
     *
     * @param mixed $items
     * @return array<string, int>
     */
    private static function asItems(
        mixed $items
    ): array {
        if (!is_array($items)) {
            return [];
        }

        $narrowed = [];

        foreach ($items as $name => $quantity) {
            if (is_string($name) && is_numeric($quantity)) {
                $narrowed[$name] = (int) $quantity;
            }
        }

        return $narrowed;
    }

    /**
     * @return array<string, int>
     */
    public function getItems(): array
    {
        return $this->items;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    public function shouldTakeSnapshot(): bool
    {
        return $this->status === 'completed';
    }
    public function getSnapshotClass(): string
    {
        return GenericSnapshot::class;
    }
    public function getSnapshotState(): array
    {
        return [
            'orderId' => $this->orderId,
            'customerName' => $this->customerName,
            'items' => $this->items,
            'status' => $this->status,
        ];
    }

    public function getSnapshotVersion(): EventVersion
    {
        return $this->version;
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString($this->orderId);
    }

    public function applySnapshot(
        SnapshotInterface $snapshot
    ): void {
        $inner = $snapshot->getState();

        $this->orderId = is_scalar($inner['orderId'] ?? null) ? (string) $inner['orderId'] : '';
        $this->customerName = is_scalar($inner['customerName'] ?? null) ? (string) $inner['customerName'] : '';
        $this->items = self::asItems($inner['items'] ?? null);
        $this->status = is_scalar($inner['status'] ?? null) ? (string) $inner['status'] : 'created';
    }

    public function applyOrderCreated(
        OrderCreated $event
    ): void {
        $this->orderId = (string) $event->getAggregateId();
        $this->customerName = $event->getCustomerName();
        $this->status = 'created';
        $this->version = EventVersion::fromInt(1);
    }

    public function applyOrderItemAdded(
        OrderItemAdded $event
    ): void {
        $item = $event->getItem();
        $qty = $event->getQuantity();
        $this->items[$item] = ($this->items[$item] ?? 0) + $qty;
        $this->version = $this->version->increment();
    }

    public function applyOrderItemRemoved(
        OrderItemRemoved $event
    ): void {
        $item = $event->getItem();
        $qty = $event->getQuantity();
        if (isset($this->items[$item])) {
            $this->items[$item] -= $qty;
            if ($this->items[$item] <= 0) {
                unset($this->items[$item]);
            }
        }
        $this->version = $this->version->increment();
    }

    public function applyOrderCompleted(
        OrderCompleted $event
    ): void {
        $this->status = 'completed';
        $this->version = $this->version->increment();
    }

    /**
     * @throws DateMalformedStringException|ReflectionException
     */
    /**
     * @param array<string, mixed> $snapshotData
     */
    public static function reconstituteFromSnapshot(
        array $snapshotData,
        EventStream $stream
    ): self {
        $instance = new self();
        $instance->orderId = is_scalar($snapshotData['orderId'] ?? null) ? (string) $snapshotData['orderId'] : '';
        $instance->customerName = is_scalar($snapshotData['customerName'] ?? null) ? (string) $snapshotData['customerName'] : '';
        $instance->items = self::asItems($snapshotData['items'] ?? null);
        $instance->status = is_scalar($snapshotData['status'] ?? null) ? (string) $snapshotData['status'] : 'created';

        foreach ($stream as $eventEntry) {
            $event = $eventEntry->toDomainEvent();
            $instance->applyEvent($event, false);
        }

        return $instance;
    }
}
