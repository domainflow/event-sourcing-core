<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

interface EventSubscriberInterface
{
    /**
     * Returns the fully-qualified event class(es) this subscriber handles.
     *
     * @return string[] List of DomainEventInterface::class strings
     */
    public static function getSubscribedTo(): array;

    /**
     * Handle a dispatched domain event.
     *
     * @param DomainEventInterface $event
     * @return void
     */
    public function handle(DomainEventInterface $event): void;
}
