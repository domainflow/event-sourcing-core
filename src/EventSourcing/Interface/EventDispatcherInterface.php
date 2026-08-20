<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

interface EventDispatcherInterface
{
    /**
     * Register a subscriber.
     *
     * @param EventSubscriberInterface $subscriber
     * @return void
     */
    public function register(EventSubscriberInterface $subscriber): void;

    /**
     * Dispatch a single event to all relevant subscribers.
     *
     * @param DomainEventInterface $event
     * @return void
     */
    public function dispatch(DomainEventInterface $event): void;

    /**
     * Dispatch multiple events.
     *
     * @param DomainEventInterface[] $events
     * @return void
     */
    public function dispatchAll(array $events): void;
}
