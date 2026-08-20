<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

interface EventFactoryInterface
{
    /**
     * Create a domain event from a payload.
     *
     * @param string $eventClass
     * @param array<string, mixed> $payload
     * @return DomainEventInterface
     */
    public function createFromPayload(string $eventClass, array $payload): DomainEventInterface;
}
