<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

use DomainFlow\EventSourcing\Event\EventMetadata;

/**
 * What the surrounding work knows, for events that have not been told.
 *
 * A library cannot know what a request is, or a queue message, or a CLI
 * invocation — so it does not try. This is the seam where the framework says
 * "the transaction currently in progress is this one, on behalf of this user,
 * for this tenant", and `MetadataEnricher` applies it to whatever the domain
 * emitted.
 *
 * Core ships `AmbientMetadataContext`, which is enough for a single process
 * and for tests. A real one belongs in the framework, where the request lives.
 */
interface MetadataProviderInterface
{
    /**
     * The metadata in force right now. Empty when nothing is.
     *
     * @return EventMetadata
     */
    public function current(): EventMetadata;
}
