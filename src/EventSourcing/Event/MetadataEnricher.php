<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Event;

use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\MetadataProviderInterface;

/**
 * Fills in what the domain did not say.
 *
 * The domain emits events; it does not know which request it is serving or
 * which message provoked it. This is where that gets attached, on the way to
 * storage and in one place, rather than in every aggregate.
 *
 * The ambient value is always a **default**, never an override. An event that
 * already carries a correlation belongs to that transaction whatever the
 * process happens to be doing around it — a saga replaying old work is the
 * obvious case, and silently re-filing its events under the current request
 * would corrupt exactly the trace this exists to produce.
 */
final readonly class MetadataEnricher
{
    public function __construct(
        private MetadataProviderInterface $provider
    ) {
    }

    /**
     * @param array<DomainEventInterface> $events
     * @return array<DomainEventInterface>
     */
    public function enrich(
        array $events
    ): array {
        $ambient = $this->provider->current();

        return array_map(
            fn (DomainEventInterface $event): DomainEventInterface => $event->withMetadata(
                $this->merge($event, $ambient)
            ),
            $events
        );
    }

    private function merge(
        DomainEventInterface $event,
        EventMetadata $ambient
    ): EventMetadata {
        $own = $event->getMetadata();

        return $own
            ->withCorrelationId($own->getCorrelationId() ?? $ambient->getCorrelationId() ?? $this->identityOf($event))
            ->withCausationId($own->getCausationId() ?? $ambient->getCausationId())
            ->withActorId($own->getActorId() ?? $ambient->getActorId())
            ->withTenantId($own->getTenantId() ?? $ambient->getTenantId())
            ->withCustom($own->getCustom() === [] ? $ambient->getCustom() : $own->getCustom());
    }

    /**
     * An event with nothing ambient around it starts its own transaction, so
     * it becomes its own correlation root. Without that, work nothing
     * triggered — a scheduled job, a CLI command — would produce events with
     * no trace at all.
     *
     * The id is read from the payload because DomainEventInterface has no
     * accessor for it; an event that does not carry one simply has no
     * correlation, which is honest.
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
}
