<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Integration;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Concurrency\ConcurrencyCheckingStorage;
use DomainFlow\EventSourcing\Concurrency\MaxVersionStrategy;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventDispatcher;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Exception\ConcurrencyException;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventDispatcherInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\EventSubscriberInterface;
use PHPUnit\Framework\TestCase;

abstract class OrderSagaIntegrationTestCase extends TestCase
{
    abstract protected function getStorage(): EventStorageInterface;

    /**
     * @throws ConcurrencyException
     */
    public function test_orderSagaGeneratesPaymentAndShipmentEvents(): void
    {
        $dispatcher = new EventDispatcher();

        $store = new ConcurrencyCheckingStorage($this->getStorage(), new MaxVersionStrategy());

        // The saga is handed the dispatcher explicitly. It used to reach it
        // through EventStorage, which dispatched from inside the storage layer
        // — so the cascade order -> payment -> shipment happened as a side
        // effect of writing. Making it explicit is the point of removing that
        // class: a store stores, and delivery is somebody's stated decision.
        $dispatcher->register(new OrderSaga($store, $dispatcher));

        $aggId = EntityIdentifier::fromString('order-123');
        $orderEvent = new AnotherOrderCreated(
            $aggId,
            EntityIdentifier::fromString(uniqid('event_', true)),
            new DateTimeImmutable(),
            EventVersion::fromInt(1)
        );
        $store->storeEvents([$orderEvent]);
        $dispatcher->dispatchAll([$orderEvent]);

        $retrievedEvents = $this->getStorage()->retrieveEvents($aggId);

        $this->assertCount(3, $retrievedEvents, "Three events should be persisted for the order saga.");
        $this->assertInstanceOf(AnotherOrderCreated::class, $retrievedEvents[0]);
        $this->assertInstanceOf(PaymentProcessed::class, $retrievedEvents[1]);
        $this->assertInstanceOf(ShipmentScheduled::class, $retrievedEvents[2]);
        $this->assertSame(1, $retrievedEvents[0]->getVersion()->toInt());
        $this->assertSame(2, $retrievedEvents[1]->getVersion()->toInt());
        $this->assertSame(3, $retrievedEvents[2]->getVersion()->toInt());
    }
}

// dummy classes
abstract class BaseEvent extends SourceEvent
{
}

final class AnotherOrderCreated extends BaseEvent
{
}

final class PaymentProcessed extends BaseEvent
{
}

final class ShipmentScheduled extends BaseEvent
{
}

final class OrderSaga implements EventSubscriberInterface
{
    private ConcurrencyCheckingStorage $store;

    private EventDispatcherInterface $dispatcher;

    public function __construct(
        ConcurrencyCheckingStorage $store,
        EventDispatcherInterface $dispatcher
    ) {
        $this->store = $store;
        $this->dispatcher = $dispatcher;
    }

    public static function getSubscribedTo(): array
    {
        return [AnotherOrderCreated::class, PaymentProcessed::class];
    }

    /**
     * @throws ConcurrencyException
     */
    public function handle(
        DomainEventInterface $event
    ): void {
        $id = EntityIdentifier::fromString(uniqid('event_', true));
        if ($event instanceof AnotherOrderCreated) {
            $payment = new PaymentProcessed(
                $event->getAggregateId(),
                $id,
                new DateTimeImmutable(),
                $event->getVersion()->increment()
            );
            $this->store->storeEvents([$payment]);
            $this->dispatcher->dispatchAll([$payment]);
        }
        if ($event instanceof PaymentProcessed) {
            $shipment = new ShipmentScheduled(
                $event->getAggregateId(),
                $id,
                new DateTimeImmutable(),
                $event->getVersion()->increment()
            );
            $this->store->storeEvents([$shipment]);
            $this->dispatcher->dispatchAll([$shipment]);
        }
    }
}
