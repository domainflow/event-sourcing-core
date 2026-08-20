<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Event;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventStream;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Traversable;

#[CoversClass(EntityIdentifier::class)]
#[CoversClass(EventVersion::class)]
#[CoversClass(EventStream::class)]
final class EventStreamTest extends TestCase
{
    public function test_eventStreamSortsEventsByVersion(): void
    {
        $agg1 = EntityIdentifier::fromString('Agg1');
        $entry1 = $this->createEventEntry($agg1, 3);
        $entry2 = $this->createEventEntry($agg1, 1);
        $entry3 = $this->createEventEntry($agg1, 2);

        $stream = new EventStream([$entry1, $entry2, $entry3]);
        $events = $stream->getEvents();

        $this->assertSame(1, $events[0]->version->toInt(), 'First event should have version 1.');
        $this->assertSame(2, $events[1]->version->toInt(), 'Second event should have version 2.');
        $this->assertSame(3, $events[2]->version->toInt(), 'Third event should have version 3.');
    }

    public function test_getIteratorReturnsCorrectEvents(): void
    {
        $agg1 = EntityIdentifier::fromString('Agg1');
        $entry1 = $this->createEventEntry($agg1, 2);
        $entry2 = $this->createEventEntry($agg1, 1);

        $stream = new EventStream([$entry1, $entry2]);
        $iterator = $stream->getIterator();

        $this->assertInstanceOf(Traversable::class, $iterator);

        $collected = iterator_to_array($iterator);

        $this->assertCount(2, $collected);
        $this->assertSame(1, $collected[0]->version->toInt());
        $this->assertSame(2, $collected[1]->version->toInt());
    }

    public function test_countReturnsCorrectNumberOfEvents(): void
    {
        $agg1 = EntityIdentifier::fromString('Agg1');
        $entry1 = $this->createEventEntry($agg1, 1);
        $entry2 = $this->createEventEntry($agg1, 2);
        $stream = new EventStream([$entry1, $entry2]);

        $this->assertSame(2, $stream->count());
        $this->assertCount(2, $stream);
    }

    public function test_filterByAggregateId(): void
    {
        $agg1 = EntityIdentifier::fromString('Agg1');
        $agg2 = EntityIdentifier::fromString('Agg2');
        $agg3 = EntityIdentifier::fromString('Agg3');

        $entry1 = $this->createEventEntry($agg1, 1);
        $entry2 = $this->createEventEntry($agg1, 2);
        $entry3 = $this->createEventEntry($agg2, 1);
        $entry4 = $this->createEventEntry($agg3, 1);
        $stream = new EventStream([$entry1, $entry2, $entry3, $entry4]);

        $filtered = $stream->filterByAggregateId($agg1);
        $this->assertInstanceOf(EventStream::class, $filtered);
        $this->assertCount(2, $filtered);

        foreach ($filtered->getEvents() as $entry) {
            $this->assertSame($agg1, $entry->aggregateId);
        }

        $emptyFiltered = $stream->filterByAggregateId(EntityIdentifier::fromString('NonExistentAgg'));

        $this->assertCount(0, $emptyFiltered);
    }

    public function test_filterByAggregateIdMatchesByValueNotObjectIdentity(): void
    {
        $agg1 = EntityIdentifier::fromString('Agg1');
        $entry1 = $this->createEventEntry($agg1, 1);
        $entry2 = $this->createEventEntry(EntityIdentifier::fromString('Agg2'), 1);
        $stream = new EventStream([$entry1, $entry2]);

        $filtered = $stream->filterByAggregateId(EntityIdentifier::fromString('Agg1'));

        $this->assertCount(1, $filtered);
        $this->assertSame($entry1, $filtered->getEvents()[0]);
    }

    public function test_getEventsReturnsSortedEvents(): void
    {
        $agg1 = EntityIdentifier::fromString('Agg1');
        $entry1 = $this->createEventEntry($agg1, 5);
        $entry2 = $this->createEventEntry($agg1, 3);
        $entry3 = $this->createEventEntry($agg1, 4);
        $stream = new EventStream([$entry1, $entry2, $entry3]);

        $events = $stream->getEvents();

        $this->assertCount(3, $events);

        $versions = array_map(static fn (EventEntry $e) => $e->version->toInt(), $events);

        $this->assertSame([3, 4, 5], $versions);
    }

    private function createEventEntry(
        EntityIdentifierInterface $aggregateId,
        int $version
    ): Stub {
        $stub = $this->createStub(EventEntry::class);
        $stub->version = EventVersion::fromInt($version);
        $stub->aggregateId = $aggregateId;

        return $stub;
    }
}
