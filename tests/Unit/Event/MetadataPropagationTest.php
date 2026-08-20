<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Event;

use DomainFlow\EventSourcing\Aggregate\AggregateId;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\AmbientMetadataContext;
use DomainFlow\EventSourcing\Event\DefaultEventEntryFactory;
use DomainFlow\EventSourcing\Event\EventDispatcher;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventMetadata;
use DomainFlow\EventSourcing\Event\EventPersistenceRecord;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\MetadataEnricher;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Facade\EventSourcingFacade;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventSubscriberInterface;
use DomainFlow\EventSourcing\Repository\AggregateRepository;
use DomainFlow\EventSourcing\Storage\InMemoryEventStorage;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Metadata is only worth having if it arrives without every aggregate
 * having to think about it.
 */
#[CoversClass(EventSourcingFacade::class)]
#[CoversClass(EventDispatcher::class)]
#[UsesClass(MetadataEnricher::class)]
#[UsesClass(AmbientMetadataContext::class)]
#[UsesClass(AggregateId::class)]
#[UsesClass(EntityIdentifier::class)]
#[UsesClass(EventId::class)]
#[UsesClass(EventVersion::class)]
#[UsesClass(OccurredOn::class)]
#[UsesClass(SourceEvent::class)]
#[UsesClass(AggregateRoot::class)]
#[UsesClass(DefaultEventEntryFactory::class)]
#[UsesClass(EventEntry::class)]
#[UsesClass(EventPersistenceRecord::class)]
#[UsesClass(AggregateRepository::class)]
#[UsesClass(InMemoryEventStorage::class)]
#[UsesClass(EventMetadata::class)]
#[UsesTrait(HasEventMetadata::class)]
final class MetadataPropagationTest extends TestCase
{
    public function test_aPersistedEventCarriesTheAmbientCorrelation(): void
    {
        $context = new AmbientMetadataContext();
        $context->begin('corr-1', actorId: 'user-7');

        $storage = new InMemoryEventStorage();
        $facade = new EventSourcingFacade(
            $storage,
            metadataEnricher: new MetadataEnricher($context)
        );

        $aggregateId = AggregateId::generate();
        $note = new MetadataNote();
        $note->write($aggregateId);

        $facade->persist($note);

        $metadata = $storage->retrieveEvents($aggregateId)[0]->getMetadata();

        $this->assertSame('corr-1', $metadata->getCorrelationId());
        $this->assertSame('user-7', $metadata->getActorId());
    }

    /**
     * The subscriber sees what was stored, not the un-enriched original. A
     * projector that reads correlation would otherwise get nothing while the
     * store has it.
     */
    public function test_subscribersSeeTheEnrichedEvent(): void
    {
        $context = new AmbientMetadataContext();
        $context->begin('corr-1');

        $seen = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->register(new MetadataRecordingSubscriber($seen));

        $facade = new EventSourcingFacade(
            new InMemoryEventStorage(),
            dispatcher: $dispatcher,
            metadataEnricher: new MetadataEnricher($context)
        );

        $note = new MetadataNote();
        $note->write(AggregateId::generate());
        $facade->persist($note);

        $this->assertCount(1, $seen);
        $this->assertSame('corr-1', $seen[0]->getMetadata()->getCorrelationId());
    }

    /**
     * The causation chain: whatever a handler emits while an event is being
     * delivered was caused by that event.
     */
    public function test_anEventEmittedWhileHandlingAnotherIsCausedByIt(): void
    {
        $context = new AmbientMetadataContext();
        $context->begin('corr-1');

        $enricher = new MetadataEnricher($context);
        $dispatcher = new EventDispatcher($context);

        $emitted = [];
        $dispatcher->register(new MetadataReactingSubscriber($enricher, $emitted));

        $trigger = new MetadataWritten(AggregateId::generate(), EventId::generate());
        $triggerId = $trigger->toArray()['eventId'];

        $dispatcher->dispatch($trigger);

        $this->assertCount(1, $emitted);
        $this->assertSame(
            $triggerId,
            $emitted[0]->getMetadata()->getCausationId(),
            'A reaction has to name what provoked it, or the chain has a hole in it.'
        );
    }

    /**
     * And the causation must not outlive the delivery, or the next unrelated
     * event inherits it.
     */
    public function test_causationDoesNotOutliveTheDelivery(): void
    {
        $context = new AmbientMetadataContext();
        $context->begin('corr-1');

        $dispatcher = new EventDispatcher($context);
        $dispatcher->dispatch(new MetadataWritten(AggregateId::generate(), EventId::generate()));

        $this->assertNull($context->current()->getCausationId());
    }
}

final class MetadataWritten extends SourceEvent
{
}

final class MetadataNote extends AggregateRoot
{
    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new self();
    }

    public function write(
        \DomainFlow\EventSourcing\Interface\EntityIdentifierInterface $aggregateId
    ): void {
        $this->applyEvent(new MetadataWritten($aggregateId, EventId::generate()));
    }

    protected function applyMetadataWritten(
        MetadataWritten $event
    ): void {
    }
}

final class MetadataRecordingSubscriber implements EventSubscriberInterface
{
    /** @param array<int, DomainEventInterface> $seen */
    public function __construct(
        private array &$seen
    ) {
    }

    public static function getSubscribedTo(): array
    {
        return [MetadataWritten::class];
    }

    public function handle(
        DomainEventInterface $event
    ): void {
        $this->seen[] = $event;
    }
}

final class MetadataReactingSubscriber implements EventSubscriberInterface
{
    /** @param array<int, DomainEventInterface> $emitted */
    public function __construct(
        private MetadataEnricher $enricher,
        private array &$emitted
    ) {
    }

    public static function getSubscribedTo(): array
    {
        return [MetadataWritten::class];
    }

    public function handle(
        DomainEventInterface $event
    ): void {
        $reaction = new MetadataWritten(AggregateId::generate(), EventId::generate());

        foreach ($this->enricher->enrich([$reaction]) as $enriched) {
            $this->emitted[] = $enriched;
        }
    }
}
