<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Event;

use DomainFlow\EventSourcing\Exception\SubscriberDispatchException;
use DomainFlow\EventSourcing\Interface\CausationTrackerInterface;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventDispatcherInterface;
use DomainFlow\EventSourcing\Interface\EventSubscriberInterface;
use Throwable;

class EventDispatcher implements EventDispatcherInterface
{
    /**
     * Subscribers by the type they asked for, which may be a concrete event
     * class, a base class, or an interface.
     *
     * @var array<string, EventSubscriberInterface[]>
     */
    private array $listeners = [];

    /**
     * @param CausationTrackerInterface|null $causation Told which event is in
     *        flight, so anything a subscriber emits while handling it can name
     *        what provoked it. Optional: a dispatcher without one still
     *        delivers, the chain simply has no links.
     */
    public function __construct(
        private readonly ?CausationTrackerInterface $causation = null
    ) {
    }

    /**
     * Register a subscriber.
     *
     * @param EventSubscriberInterface $subscriber
     */
    public function register(
        EventSubscriberInterface $subscriber
    ): void {
        foreach ($subscriber::getSubscribedTo() as $subscribedType) {
            if (!isset($this->listeners[$subscribedType])) {
                $this->listeners[$subscribedType] = [];
            }

            if (!in_array($subscriber, $this->listeners[$subscribedType], true)) {
                $this->listeners[$subscribedType][] = $subscriber;
            }
        }
    }

    /**
     * Dispatch a single event to all relevant subscribers.
     *
     * Every subscriber is given its turn even if an earlier one threw. They
     * are independent readers of the same event, and letting the first failure
     * cancel the rest turns one broken projector into a silent outage of every
     * other one. Failures are collected and raised together afterwards.
     *
     * @param DomainEventInterface $event
     * @throws SubscriberDispatchException
     */
    public function dispatch(
        DomainEventInterface $event
    ): void {
        $failures = $this->deliver($event);

        if ($failures !== []) {
            throw new SubscriberDispatchException($failures);
        }
    }

    /**
     * Dispatch multiple events.
     *
     * A failure on one event does not stop the others, for the same reason a
     * failure in one subscriber does not stop the others.
     *
     * @param DomainEventInterface[] $events
     * @throws SubscriberDispatchException
     */
    public function dispatchAll(
        array $events
    ): void {
        $failures = [];

        foreach ($events as $event) {
            foreach ($this->deliver($event) as $failure) {
                $failures[] = $failure;
            }
        }

        if ($failures !== []) {
            throw new SubscriberDispatchException($failures);
        }
    }

    /**
     * Hands the event to every matching subscriber and returns what went
     * wrong.
     *
     * @param DomainEventInterface $event
     * @return list<DispatchFailure>
     */
    private function deliver(
        DomainEventInterface $event
    ): array {
        $failures = [];

        // Set around the whole delivery and cleared afterwards, so causation
        // cannot outlive it and be inherited by the next, unrelated event.
        $this->causation?->causedBy($this->identityOf($event));

        try {
            foreach ($this->subscribersFor($event) as $subscriber) {
                try {
                    $subscriber->handle($event);
                } catch (Throwable $throwable) {
                    $failures[] = new DispatchFailure($subscriber, $event, $throwable);
                }
            }
        } finally {
            $this->causation?->causedBy(null);
        }

        return $failures;
    }

    /**
     * The event's own id, read from the payload because DomainEventInterface
     * has no accessor for it.
     *
     * @param DomainEventInterface $event
     * @return string|null
     */
    private function identityOf(
        DomainEventInterface $event
    ): ?string {
        $eventId = $event->toArray()['eventId'] ?? null;

        return is_string($eventId) && $eventId !== '' ? $eventId : null;
    }

    /**
     * The subscribers that asked for this event, by concrete class, by any of
     * its parents, or by any interface it implements.
     *
     * Matching only the concrete class would mean a bounded context with an
     * event hierarchy has to register every leaf separately, and a marker
     * interface could not be subscribed to at all.
     *
     * A subscriber that asked for several matching types — the concrete class
     * and its base, say — still receives the event once.
     *
     * @param DomainEventInterface $event
     * @return list<EventSubscriberInterface>
     */
    private function subscribersFor(
        DomainEventInterface $event
    ): array {
        $matching = [];

        foreach ($this->listeners as $subscribedType => $subscribers) {
            if (!is_a($event, $subscribedType)) {
                continue;
            }

            foreach ($subscribers as $subscriber) {
                if (!in_array($subscriber, $matching, true)) {
                    $matching[] = $subscriber;
                }
            }
        }

        return $matching;
    }
}
