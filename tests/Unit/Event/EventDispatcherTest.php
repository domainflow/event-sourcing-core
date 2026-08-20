<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Event;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\DispatchFailure;
use DomainFlow\EventSourcing\Event\EventDispatcher;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Exception\SubscriberDispatchException;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventSubscriberInterface;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(EventDispatcher::class)]
#[CoversClass(DispatchFailure::class)]
#[CoversClass(SubscriberDispatchException::class)]
final class EventDispatcherTest extends TestCase
{
    public function test_dispatchWithoutSubscribersDoesNothing(): void
    {
        $dispatcher = new EventDispatcher();
        $event = new DummyEvent('test');

        $dispatcher->dispatch($event);
        $this->assertTrue(true);
    }

    public function test_registerAndDispatchCallsSubscriber(): void
    {
        $dispatcher = new EventDispatcher();
        $subscriber = new DummySubscriber();
        $dispatcher->register($subscriber);
        $event = new DummyEvent('event1');
        $dispatcher->dispatch($event);

        $this->assertCount(1, $subscriber->handledEvents);
        $this->assertSame($event, $subscriber->handledEvents[0]);
    }

    public function test_dispatchAllDispatchesMultipleEvents(): void
    {
        $dispatcher = new EventDispatcher();
        $subscriber = new DummySubscriber();
        $dispatcher->register($subscriber);

        $event1 = new DummyEvent('event1');
        $event2 = new DummyEvent('event2');
        $dispatcher->dispatchAll([$event1, $event2]);

        $this->assertCount(2, $subscriber->handledEvents);
        $this->assertSame($event1, $subscriber->handledEvents[0]);
        $this->assertSame($event2, $subscriber->handledEvents[1]);
    }

    public function test_multipleSubscribersForSameEvent(): void
    {
        $dispatcher = new EventDispatcher();
        $subscriber1 = new DummySubscriber();
        $subscriber2 = new DummySubscriber();
        $dispatcher->register($subscriber1);
        $dispatcher->register($subscriber2);

        $event = new DummyEvent('eventX');
        $dispatcher->dispatch($event);

        $this->assertCount(1, $subscriber1->handledEvents);
        $this->assertCount(1, $subscriber2->handledEvents);
        $this->assertSame($event, $subscriber1->handledEvents[0]);
        $this->assertSame($event, $subscriber2->handledEvents[0]);
    }

    /**
     * Subscribers are independent readers of the same event. Letting the first
     * failure cancel the rest turns one broken projector into a silent outage
     * of every other one, which is a far larger problem than the one that
     * failed.
     */
    public function test_aFailingSubscriberDoesNotRobTheOthersOfTheEvent(): void
    {
        $dispatcher = new EventDispatcher();
        $exploding = new ExplodingSubscriber();
        $healthy = new DummySubscriber();

        $dispatcher->register($exploding);
        $dispatcher->register($healthy);

        try {
            $dispatcher->dispatch(new DummyEvent('event1'));
            $this->fail('The failure has to surface, just not before everyone has had their turn.');
        } catch (SubscriberDispatchException $exception) {
            $this->assertCount(1, $exception->getFailures());
            $this->assertSame($exploding, $exception->getFailures()[0]->getSubscriber());
        }

        $this->assertCount(1, $healthy->handledEvents, 'The subscriber registered after the failing one still had to receive the event.');
    }

    public function test_everyFailureIsReported_notJustTheFirst(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->register(new ExplodingSubscriber('first'));
        $dispatcher->register(new ExplodingSubscriber('second'));

        try {
            $dispatcher->dispatch(new DummyEvent());
            $this->fail('Expected a dispatch failure.');
        } catch (SubscriberDispatchException $exception) {
            $messages = array_map(
                static fn ($failure): string => $failure->getFailure()->getMessage(),
                $exception->getFailures()
            );

            $this->assertSame(['first', 'second'], $messages, 'A caller retrying or dead-lettering needs all of them.');
        }
    }

    public function test_dispatchAllKeepsGoingAfterAnEventFails(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->register(new ExplodingSubscriber());
        $healthy = new DummySubscriber();
        $dispatcher->register($healthy);

        try {
            $dispatcher->dispatchAll([new DummyEvent('one'), new DummyEvent('two')]);
            $this->fail('Expected a dispatch failure.');
        } catch (SubscriberDispatchException $exception) {
            $this->assertCount(2, $exception->getFailures(), 'Both events were attempted.');
        }

        $this->assertCount(2, $healthy->handledEvents, 'A failure on the first event must not swallow the second.');
    }

    public function test_aSubscriberCanListenOnABaseClass(): void
    {
        $dispatcher = new EventDispatcher();
        $subscriber = new BaseOrderEventSubscriber();
        $dispatcher->register($subscriber);

        $event = new OrderPlaced();
        $dispatcher->dispatch($event);

        $this->assertSame([$event], $subscriber->handledEvents, 'An event hierarchy should not have to be registered leaf by leaf.');
    }

    public function test_aSubscriberCanListenOnAMarkerInterface(): void
    {
        $dispatcher = new EventDispatcher();
        $subscriber = new AuditableEventSubscriber();
        $dispatcher->register($subscriber);

        $event = new OrderPlaced();
        $dispatcher->dispatch($event);

        $this->assertSame([$event], $subscriber->handledEvents, 'A marker interface is the natural way to express a cross-cutting concern.');
    }

    public function test_aSubscriberMatchingSeveralRegisteredTypesStillReceivesTheEventOnce(): void
    {
        $dispatcher = new EventDispatcher();
        $subscriber = new BaseAndConcreteSubscriber();
        $dispatcher->register($subscriber);

        $dispatcher->dispatch(new OrderPlaced());

        $this->assertCount(1, $subscriber->handledEvents, 'Subscribing to both a base class and its subclass must not double-deliver.');
    }

    public function test_anUnrelatedEventReachesNoSubscriber(): void
    {
        $dispatcher = new EventDispatcher();
        $subscriber = new BaseOrderEventSubscriber();
        $dispatcher->register($subscriber);

        $dispatcher->dispatch(new DummyEvent());

        $this->assertSame([], $subscriber->handledEvents);
    }
}

# dummy classes
final class DummyEvent implements DomainEventInterface
{
    use HasEventMetadata;

    private string $id;
    private EventVersion $version;

    public function __construct(
        string $id = 'dummy'
    ) {
        $this->id = $id;
    }

    public function getAggregateId(): EntityIdentifier
    {
        return EntityIdentifier::fromString('aggregate');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return ['id' => $this->id];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class DummySubscriber implements EventSubscriberInterface
{
    /** @var DomainEventInterface[] */
    public array $handledEvents = [];

    public static function getSubscribedTo(): array
    {
        return [DummyEvent::class];
    }

    public function handle(
        DomainEventInterface $event
    ): void {
        $this->handledEvents[] = $event;
    }
}

interface AuditableEvent
{
}

class BaseOrderEvent implements DomainEventInterface, AuditableEvent
{
    use HasEventMetadata;

    private EventVersion $version;

    public function getAggregateId(): EntityIdentifier
    {
        return EntityIdentifier::fromString('order');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return [];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class OrderPlaced extends BaseOrderEvent
{
}

final class BaseOrderEventSubscriber implements EventSubscriberInterface
{
    /** @var DomainEventInterface[] */
    public array $handledEvents = [];

    public static function getSubscribedTo(): array
    {
        return [BaseOrderEvent::class];
    }

    public function handle(
        DomainEventInterface $event
    ): void {
        $this->handledEvents[] = $event;
    }
}

final class AuditableEventSubscriber implements EventSubscriberInterface
{
    /** @var DomainEventInterface[] */
    public array $handledEvents = [];

    public static function getSubscribedTo(): array
    {
        return [AuditableEvent::class];
    }

    public function handle(
        DomainEventInterface $event
    ): void {
        $this->handledEvents[] = $event;
    }
}

final class BaseAndConcreteSubscriber implements EventSubscriberInterface
{
    /** @var DomainEventInterface[] */
    public array $handledEvents = [];

    public static function getSubscribedTo(): array
    {
        return [BaseOrderEvent::class, OrderPlaced::class];
    }

    public function handle(
        DomainEventInterface $event
    ): void {
        $this->handledEvents[] = $event;
    }
}

final class ExplodingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $message = 'boom'
    ) {
    }

    public static function getSubscribedTo(): array
    {
        return [DummyEvent::class];
    }

    public function handle(
        DomainEventInterface $event
    ): void {
        throw new RuntimeException($this->message);
    }
}
