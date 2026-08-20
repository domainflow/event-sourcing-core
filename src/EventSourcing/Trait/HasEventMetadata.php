<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Trait;

use DomainFlow\EventSourcing\Event\EventMetadata;

/**
 * The default implementation of the metadata half of DomainEventInterface.
 *
 * Metadata is infrastructure's business, not the domain's, so an event class
 * has nothing interesting to say about how it is held. `SourceEvent` uses this
 * trait, and so should any event implementing the interface directly — one
 * line instead of two methods that would be identical everywhere.
 */
trait HasEventMetadata
{
    private ?EventMetadata $metadata = null;

    public function getMetadata(): EventMetadata
    {
        return $this->metadata ?? EventMetadata::empty();
    }

    public function withMetadata(
        EventMetadata $metadata
    ): static {
        $copy = clone $this;
        $copy->metadata = $metadata;

        return $copy;
    }
}
