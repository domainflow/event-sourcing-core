<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Event;

use DomainFlow\EventSourcing\Aggregate\AggregateId;
use DomainFlow\EventSourcing\Event\AmbientMetadataContext;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventMetadata;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\MetadataEnricher;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

#[CoversClass(MetadataEnricher::class)]
#[CoversClass(AmbientMetadataContext::class)]
#[UsesClass(EventMetadata::class)]
#[UsesClass(EventId::class)]
#[UsesClass(AggregateId::class)]
#[UsesClass(SourceEvent::class)]
#[UsesClass(EventVersion::class)]
#[UsesClass(OccurredOn::class)]
#[UsesTrait(HasEventMetadata::class)]
final class MetadataEnricherTest extends TestCase
{
    public function test_itStampsTheAmbientCorrelationOnEveryEvent(): void
    {
        $context = new AmbientMetadataContext();
        $context->begin('corr-1');

        $enriched = (new MetadataEnricher($context))->enrich([$this->event(), $this->event()]);

        $this->assertSame('corr-1', $enriched[0]->getMetadata()->getCorrelationId());
        $this->assertSame('corr-1', $enriched[1]->getMetadata()->getCorrelationId());
    }

    /**
     * The ambient value is a default, not an override. An event that already
     * carries a correlation belongs to that transaction, whatever the process
     * happens to be doing around it.
     */
    public function test_anExplicitValueWins(): void
    {
        $context = new AmbientMetadataContext();
        $context->begin('corr-ambient');

        $event = $this->event()->withMetadata(EventMetadata::empty()->withCorrelationId('corr-explicit'));

        $enriched = (new MetadataEnricher($context))->enrich([$event]);

        $this->assertSame('corr-explicit', $enriched[0]->getMetadata()->getCorrelationId());
    }

    /**
     * With nothing ambient, an event starts its own transaction and becomes
     * its own correlation root — which is what makes a trace exist at all for
     * work that was not triggered by anything traced.
     */
    public function test_anEventWithNoAmbientCorrelationBecomesItsOwnRoot(): void
    {
        $event = $this->event();
        $eventId = $event->toArray()['eventId'];

        $enriched = (new MetadataEnricher(new AmbientMetadataContext()))->enrich([$event]);

        $this->assertSame($eventId, $enriched[0]->getMetadata()->getCorrelationId());
    }

    public function test_itStampsTheAmbientCausation(): void
    {
        $context = new AmbientMetadataContext();
        $context->begin('corr-1');
        $context->causedBy('cause-1');

        $enriched = (new MetadataEnricher($context))->enrich([$this->event()]);

        $this->assertSame('cause-1', $enriched[0]->getMetadata()->getCausationId());
    }

    public function test_itStampsTheActorAndTenant(): void
    {
        $context = new AmbientMetadataContext();
        $context->begin('corr-1', actorId: 'user-7', tenantId: 'tenant-a');

        $metadata = (new MetadataEnricher($context))->enrich([$this->event()])[0]->getMetadata();

        $this->assertSame('user-7', $metadata->getActorId());
        $this->assertSame('tenant-a', $metadata->getTenantId());
    }

    /**
     * A worker handles one message after another in the same process. What the
     * previous one established must not leak into the next.
     */
    public function test_endingATransactionClearsWhatItEstablished(): void
    {
        $context = new AmbientMetadataContext();
        $context->begin('corr-1', actorId: 'user-7');
        $context->causedBy('cause-1');
        $context->end();

        $metadata = (new MetadataEnricher($context))->enrich([$this->event()])[0]->getMetadata();

        $this->assertNull($metadata->getCausationId());
        $this->assertNull($metadata->getActorId());
    }

    private function event(): MetadataDummyEvent
    {
        return new MetadataDummyEvent(AggregateId::generate(), EventId::generate());
    }
}

final class MetadataDummyEvent extends SourceEvent
{
}
