<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Event;

use DomainFlow\EventSourcing\Interface\CausationTrackerInterface;
use DomainFlow\EventSourcing\Interface\MetadataProviderInterface;

/**
 * The metadata currently in force, held in the process.
 *
 * Enough for a single-process application and for tests, and the reference for
 * what a framework-side implementation has to do. It is deliberately mutable
 * and deliberately not static, so two contexts in one service cannot
 * overwrite each other.
 *
 * A long-running worker calls begin() per message and end() after it, so what
 * one message established cannot leak into the next. That leak is the failure
 * mode worth designing against: it does not break anything visibly, it just
 * files the wrong events under the wrong transaction.
 */
final class AmbientMetadataContext implements MetadataProviderInterface, CausationTrackerInterface
{
    private EventMetadata $current;

    public function __construct()
    {
        $this->current = EventMetadata::empty();
    }

    /**
     * Starts a unit of work — a request, a consumed message, a command.
     *
     * @param string|null $correlationId Null lets each event become its own
     *        correlation root, which is what work nothing triggered should do.
     * @param string|null $actorId
     * @param string|null $tenantId
     * @return void
     */
    public function begin(
        ?string $correlationId = null,
        ?string $actorId = null,
        ?string $tenantId = null
    ): void {
        $this->current = EventMetadata::empty()
            ->withCorrelationId($correlationId)
            ->withActorId($actorId)
            ->withTenantId($tenantId);
    }

    /**
     * Records that whatever happens next happens because of this event.
     *
     * Called around the delivery of an event, so anything a handler emits
     * carries the id of what provoked it.
     *
     * @param string|null $eventId
     * @return void
     */
    public function causedBy(
        ?string $eventId
    ): void {
        $this->current = $this->current->withCausationId($eventId);
    }

    /**
     * Ends the unit of work. Everything it established is forgotten.
     *
     * @return void
     */
    public function end(): void
    {
        $this->current = EventMetadata::empty();
    }

    public function current(): EventMetadata
    {
        return $this->current;
    }
}
