<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Concurrency;

use DomainFlow\EventSourcing\Concurrency\ConcurrencyCheckingStorage;
use DomainFlow\EventSourcing\Concurrency\ConcurrencyCheckStrategyInterface;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\GlobalEventPage;
use DomainFlow\EventSourcing\Exception\ConcurrencyException;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[UsesClass(GlobalEventPage::class)]
#[CoversClass(EntityIdentifier::class)]
#[CoversClass(EventVersion::class)]
#[CoversClass(ConcurrencyCheckingStorage::class)]
final class ConcurrencyCheckingStorageTest extends TestCase
{
    private EventStorageInterface|MockObject $innerStorage;
    private ConcurrencyCheckStrategyInterface|Stub $strategy;
    private ConcurrencyCheckingStorage $checkingStorage;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->innerStorage = $this->createMock(EventStorageInterface::class);
        $this->strategy = $this->createStub(ConcurrencyCheckStrategyInterface::class);

        $this->checkingStorage = new ConcurrencyCheckingStorage(
            $this->innerStorage,
            $this->strategy
        );
    }

    /**
     * @throws ConcurrencyException|Exception
     */
    public function test_storeEventsNoConflict(): void
    {
        $fakeEvent = $this->createStub(DomainEventInterface::class);
        $fakeEvents = [$fakeEvent];

        $strategy = $this->createMock(ConcurrencyCheckStrategyInterface::class);
        $strategy
            ->expects($this->once())
            ->method('assertNoConflict')
            ->with($fakeEvents, $this->innerStorage);

        $this->innerStorage
            ->expects($this->once())
            ->method('storeEvents')
            ->with($fakeEvents);

        $checkingStorage = new ConcurrencyCheckingStorage($this->innerStorage, $strategy);
        $checkingStorage->storeEvents($fakeEvents);
    }

    /**
     * @throws Exception
     */
    public function test_storeEventsThrowsOnConflict(): void
    {
        $fakeEvent = $this->createStub(DomainEventInterface::class);
        $fakeEvents = [$fakeEvent];

        $this->strategy
            ->method('assertNoConflict')
            ->willThrowException(new ConcurrencyException('Conflict detected'));

        $this->innerStorage
            ->expects($this->never())
            ->method('storeEvents');

        $this->expectException(ConcurrencyException::class);
        $this->expectExceptionMessage('Conflict detected');

        $this->checkingStorage->storeEvents($fakeEvents);
    }

    public function test_retrieveEventsForwardsCall(): void
    {
        $aggregateId = EntityIdentifier::fromString('some-aggregate');
        $fakeEvents = ['event1', 'event2'];

        $this->innerStorage
            ->expects($this->once())
            ->method('retrieveEvents')
            ->with($aggregateId)
            ->willReturn($fakeEvents);

        $actual = $this->checkingStorage->retrieveEvents($aggregateId);
        $this->assertSame($fakeEvents, $actual);
    }

    public function test_retrieveEventsFromVersionForwardsCall(): void
    {
        $aggregateId = EntityIdentifier::fromString('tail-aggregate');
        $afterVersion = EventVersion::fromInt(7);
        $fakeEvents = ['event8', 'event9'];

        $this->innerStorage
            ->expects($this->once())
            ->method('retrieveEventsFromVersion')
            ->with($aggregateId, $afterVersion)
            ->willReturn($fakeEvents);

        $this->assertSame($fakeEvents, $this->checkingStorage->retrieveEventsFromVersion($aggregateId, $afterVersion));
    }

    public function test_retrieveEventsFromPositionForwardsCall(): void
    {
        $page = new GlobalEventPage([], '42');

        $this->innerStorage
            ->expects($this->once())
            ->method('retrieveEventsFromPosition')
            ->with('41', 10)
            ->willReturn($page);

        $this->assertSame($page, $this->checkingStorage->retrieveEventsFromPosition('41', 10));
    }

    public function test_deleteEventsForwardsCall(): void
    {
        $aggregateId = EntityIdentifier::fromString('delete-agg');

        $this->innerStorage
            ->expects($this->once())
            ->method('deleteEvents')
            ->with($aggregateId);

        $this->checkingStorage->deleteEvents($aggregateId);
    }

    public function test_retrieveAllEventsForwardsCall(): void
    {
        $fakeAll = ['evtA', 'evtB'];

        $this->innerStorage
            ->expects($this->once())
            ->method('retrieveAllEvents')
            ->willReturn($fakeAll);

        $actual = $this->checkingStorage->retrieveAllEvents();
        $this->assertSame($fakeAll, $actual);
    }

    public function test_retrievePaginatedEventsForwardsCall(): void
    {
        $offset = 10;
        $limit = 5;
        $fakePaged = ['evtX', 'evtY'];

        $this->innerStorage
            ->expects($this->once())
            ->method('retrievePaginatedEvents')
            ->with($offset, $limit)
            ->willReturn($fakePaged);

        $actual = $this->checkingStorage->retrievePaginatedEvents($offset, $limit);
        $this->assertSame($fakePaged, $actual);
    }

    public function test_getCurrentMaxVersionForwardsCall(): void
    {
        $aggregateId = EntityIdentifier::fromString('version-check');
        $fakeMax = EventVersion::fromInt(7);

        $this->innerStorage
            ->expects($this->once())
            ->method('getCurrentMaxVersion')
            ->with($aggregateId)
            ->willReturn($fakeMax);

        $actual = $this->checkingStorage->getCurrentMaxVersion($aggregateId);
        $this->assertSame($fakeMax, $actual);
    }
}
