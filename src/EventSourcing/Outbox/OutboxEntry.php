<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Outbox;

use DomainFlow\EventSourcing\Interface\DomainEventInterface;

/**
 * One pending delivery.
 *
 */
final readonly class OutboxEntry
{
    public function __construct(
        private string $id,
        private DomainEventInterface $event,
        private int $attempts
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEvent(): DomainEventInterface
    {
        return $this->event;
    }

    /**
     * How many times delivery has been tried and failed. A relay uses this to
     * give up rather than retry a poisoned entry forever.
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }
}
