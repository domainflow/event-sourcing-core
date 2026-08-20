<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

interface EventUpcasterInterface
{
    /**
     * @param string $eventType
     * @return bool
     */
    public function supports(string $eventType): bool;

    /**
     * Upcast the event to the latest version.
     *
     * @param string $eventType
     * @param array<string, mixed> $data
     * @return DomainEventInterface
     */
    public function upcast(string $eventType, array $data): DomainEventInterface;
}
