<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Aggregate;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AggregateRoot::class)]
#[CoversClass(EventVersion::class)]
#[CoversClass(SourceEvent::class)]
#[UsesClass(EntityIdentifier::class)]
#[UsesClass(EventId::class)]
#[UsesClass(OccurredOn::class)]
final class AggregateRootVersioningTest extends TestCase
{
    private function id(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString('versioning-agg');
    }

    public function test_aggregateWithoutHistoryHasAnUnassignedVersion(): void
    {
        $aggregate = new VersioningAggregate();

        $this->assertSame(0, $aggregate->getAggregateVersion()->toInt());
        $this->assertFalse($aggregate->getAggregateVersion()->isAssigned());
    }

    public function test_eachAppliedEventGetsTheNextVersion(): void
    {
        $aggregate = new VersioningAggregate();

        $aggregate->record($this->id());
        $aggregate->record($this->id());
        $aggregate->record($this->id());

        $versions = array_map(
            static fn ($event): int => $event->getVersion()->toInt(),
            $aggregate->getUncommittedEvents()
        );

        $this->assertSame([1, 2, 3], $versions);
        $this->assertSame(3, $aggregate->getAggregateVersion()->toInt());
    }

    public function test_anExplicitlySuppliedVersionIsHonoured(): void
    {
        $aggregate = new VersioningAggregate();

        $aggregate->applyEvent(new VersioningEvent($this->id(), null, null, EventVersion::fromInt(7)));

        $this->assertSame(7, $aggregate->getUncommittedEvents()[0]->getVersion()->toInt());
        $this->assertSame(7, $aggregate->getAggregateVersion()->toInt());
    }

    public function test_replayingHistoryCarriesTheVersionForward(): void
    {
        $aggregate = new VersioningAggregate();

        foreach ([1, 2, 3] as $version) {
            $aggregate->applyEvent(
                new VersioningEvent($this->id(), null, null, EventVersion::fromInt($version)),
                false
            );
        }

        $this->assertSame(3, $aggregate->getAggregateVersion()->toInt());
        $this->assertCount(0, $aggregate->getUncommittedEvents(), 'Replayed events are not uncommitted.');

        $aggregate->record($this->id());

        $this->assertSame(4, $aggregate->getUncommittedEvents()[0]->getVersion()->toInt());
    }

    public function test_restoreVersionSeedsTheAggregate(): void
    {
        $aggregate = new VersioningAggregate();
        $aggregate->restoreVersion(EventVersion::fromInt(41));

        $aggregate->record($this->id());

        $this->assertSame(42, $aggregate->getUncommittedEvents()[0]->getVersion()->toInt());
    }

    /**
     * The handler cache is keyed per aggregate class: an aggregate that
     * declares no handler for an event class must not stop another aggregate
     * class from having its handler resolved, nor be made to call one it does
     * not declare.
     */
    public function test_handlerResolutionIsIsolatedPerAggregateClass(): void
    {
        $silent = new VersioningSilentAggregate();
        $silent->record($this->id());
        $this->assertSame(1, $silent->getAggregateVersion()->toInt());

        $handling = new VersioningAggregate();
        $handling->record($this->id());

        $this->assertSame(1, $handling->handled, 'Handler must still be resolved for this class.');
    }

    public function test_handlerResolutionIsIsolatedInTheReverseOrderToo(): void
    {
        $handling = new VersioningAggregate();
        $handling->record($this->id());
        $this->assertSame(1, $handling->handled);

        $silent = new VersioningSilentAggregate();
        $silent->record($this->id());

        $this->assertSame(1, $silent->getAggregateVersion()->toInt());
    }
}

final class VersioningEvent extends SourceEvent
{
    public function __construct(
        EntityIdentifierInterface $aggregateId,
        ?EntityIdentifierInterface $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
    }
}

final class VersioningAggregate extends AggregateRoot
{
    public int $handled = 0;

    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function record(
        EntityIdentifierInterface $aggregateId
    ): void {
        $this->applyEvent(new VersioningEvent($aggregateId));
    }

    protected function applyVersioningEvent(
        VersioningEvent $event
    ): void {
        $this->handled++;
    }
}

final class VersioningSilentAggregate extends AggregateRoot
{
    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function record(
        EntityIdentifierInterface $aggregateId
    ): void {
        $this->applyEvent(new VersioningEvent($aggregateId));
    }
}
