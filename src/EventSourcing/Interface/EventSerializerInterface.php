<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

interface EventSerializerInterface
{
    /**
     * Serialize a domain event to a string.
     *
     * @param DomainEventInterface $event
     * @return string
     */
    public function serialize(DomainEventInterface $event): string;

    /**
     * Deserialize a domain event from a string.
     *
     * @param string $data
     * @return DomainEventInterface
     */
    public function deserialize(string $data): DomainEventInterface;
}
