<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Aggregate;

use ArrayIterator;
use DateMalformedStringException;
use DateTimeImmutable;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventStream;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use ReflectionException;

#[CoversClass(EventVersion::class)]
#[CoversClass(AggregateRoot::class)]
final class AggregateRootTest extends TestCase
{
    public function test_applyEventWithHandler(): void
    {
        $aggregate = new DummyAggregate();
        $event = new DummyEvent('agg1', 1);

        $aggregate->applyEvent($event, true);

        $this->assertCount(1, $aggregate->appliedEvents, 'Handler should have been called.');
        $this->assertSame($event, $aggregate->appliedEvents[0]);

        $uncommitted = $aggregate->getUncommittedEvents();
        $this->assertCount(1, $uncommitted, 'Event should be in uncommitted events.');
        $this->assertSame($event, $uncommitted[0]);

        $this->assertSame(1, $aggregate->handlerCallCount);
    }

    public function test_applyEventWithoutHandler(): void
    {
        $aggregate = new DummyAggregate();
        $event = new DummyEventWithoutHandler('agg1', 1);

        $aggregate->applyEvent($event, true);

        $this->assertEmpty($aggregate->appliedEvents, 'No handler should be called.');

        $uncommitted = $aggregate->getUncommittedEvents();

        $this->assertCount(1, $uncommitted);
        $this->assertSame($event, $uncommitted[0]);
    }

    public function test_applyEventNotNewDoesNotAddToUncommittedEvents(): void
    {
        $aggregate = new DummyAggregate();
        $event = new DummyEvent('agg1', 1);

        $aggregate->applyEvent($event, false);

        $this->assertCount(1, $aggregate->appliedEvents);
        $this->assertSame($event, $aggregate->appliedEvents[0]);
        $this->assertEmpty($aggregate->getUncommittedEvents(), 'Event should not be tracked as uncommitted.');
    }

    public function test_applyEventSetsVersionWhenZeroOnRealEvent(): void
    {
        $aggregate = new DummyAggregate();

        $event = new DummyEvent('agg1', 0);

        $aggregate->applyEvent($event);

        $this->assertCount(1, $aggregate->getUncommittedEvents());

        $applied = $aggregate->getUncommittedEvents()[0];
        $this->assertSame(1, $applied->getVersion()->toInt(), 'Event version should have been set to 1');
    }

    public function test_getAndClearUncommittedEvents(): void
    {
        $aggregate = new DummyAggregate();
        $event1 = new DummyEvent('agg1', 1);
        $event2 = new DummyEvent('agg1', 2);

        $aggregate->applyEvent($event1, true);
        $aggregate->applyEvent($event2, true);

        $uncommitted = $aggregate->getUncommittedEvents();
        $this->assertCount(2, $uncommitted);

        $aggregate->clearUncommittedEvents();
        $this->assertEmpty($aggregate->getUncommittedEvents(), 'Uncommitted events should be cleared.');
    }

    /**
     * @throws ReflectionException|DateMalformedStringException| Exception
     */
    public function test_reconstituteFromEventStreamUsingMocks(): void
    {
        $event1 = new DummyEvent('agg1', 1);
        $event2 = new DummyEvent('agg1', 2);

        $mockEntry1 = $this->createMock(EventEntry::class);
        $mockEntry1->expects($this->once())
            ->method('toDomainEvent')
            ->willReturn($event1);

        $mockEntry2 = $this->createMock(EventEntry::class);
        $mockEntry2->expects($this->once())
            ->method('toDomainEvent')
            ->willReturn($event2);

        $mockStream = $this->createMock(EventStream::class);
        $mockStream->expects($this->once())
            ->method('getIterator')
            ->willReturn(new ArrayIterator([$mockEntry1, $mockEntry2]));

        $aggregate = DummyAggregate::reconstitute($mockStream);

        $this->assertCount(2, $aggregate->appliedEvents);
        $this->assertSame($event1, $aggregate->appliedEvents[0]);
        $this->assertSame($event2, $aggregate->appliedEvents[1]);
        $this->assertEmpty($aggregate->getUncommittedEvents());
        $this->assertSame(2, $aggregate->handlerCallCount);
    }

    public function test_reconstituteDirectlyOnAbstractClassThrows(): void
    {
        $stubStream = $this->createStub(EventStream::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(AggregateRoot::class . ' is abstract and cannot be reconstituted directly.');

        AggregateRoot::reconstitute($stubStream);
    }
}

# dummy classes
final class DummyAggregate extends AggregateRoot
{
    public array $appliedEvents = [];
    public int $handlerCallCount = 0;

    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function applyDummyEvent(
        DomainEventInterface $event
    ): void {
        $this->appliedEvents[] = $event;
        $this->handlerCallCount++;
    }
}

final class DummyEvent implements DomainEventInterface
{
    use HasEventMetadata;

    private string $aggregateId;
    private EventVersion $version;
    private DateTimeImmutable $occurredOn;

    public function __construct(
        string $aggregateId,
        int $version,
        ?DateTimeImmutable $occurredOn = null
    ) {
        $this->aggregateId = $aggregateId;
        $this->version = EventVersion::fromInt($version);
        $this->occurredOn = $occurredOn ?? new DateTimeImmutable();
    }

    public function getAggregateId(): EntityIdentifier
    {
        return EntityIdentifier::fromString($this->aggregateId);
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    public function toArray(): array
    {
        return [
            'aggregateId' => $this->aggregateId,
            'version' => $this->version->toInt(),
            'occurredOn' => $this->occurredOn->format('Y-m-d H:i:s.u'),
        ];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class DummyEventWithoutHandler implements DomainEventInterface
{
    use HasEventMetadata;

    private string $aggregateId;
    protected EventVersion $version;
    private DateTimeImmutable $occurredOn;

    public function __construct(
        string $aggregateId,
        int $version,
        ?DateTimeImmutable $occurredOn = null
    ) {
        $this->aggregateId = $aggregateId;
        $this->version = EventVersion::fromInt($version);
        $this->occurredOn = $occurredOn ?? new DateTimeImmutable();
    }

    public function getAggregateId(): EntityIdentifier
    {
        return EntityIdentifier::fromString($this->aggregateId);
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return $this->occurredOn;
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    public function toArray(): array
    {
        return [
            'aggregateId' => $this->aggregateId,
            'version' => $this->version->toInt(),
            'occurredOn' => $this->occurredOn->format('Y-m-d H:i:s.u'),
        ];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}
