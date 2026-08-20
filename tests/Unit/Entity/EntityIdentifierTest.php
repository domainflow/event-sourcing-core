<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Entity;

use DomainFlow\EventSourcing\Aggregate\AggregateId;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

#[CoversClass(EntityIdentifier::class)]
#[CoversClass(AggregateId::class)]
#[CoversClass(EventId::class)]
final class EntityIdentifierTest extends TestCase
{
    public function test_canBeCreatedFromString(): void
    {
        $identifier = EntityIdentifier::fromString('12345');
        $this->assertEquals('12345', (string) $identifier);
    }

    public function test_canBeConvertedToString(): void
    {
        $identifier = new EntityIdentifier('12345');
        $this->assertEquals('12345', (string) $identifier);
    }

    public function test_equalsReturnsTrueForSameValue(): void
    {
        $identifier1 = new EntityIdentifier('12345');
        $identifier2 = new EntityIdentifier('12345');
        $this->assertTrue($identifier1->equals($identifier2));
    }

    public function test_equalsReturnsFalseForDifferentValue(): void
    {
        $identifier1 = new EntityIdentifier('12345');
        $identifier2 = new EntityIdentifier('67890');
        $this->assertFalse($identifier1->equals($identifier2));
    }

    /**
     * @throws Exception
     */
    public function test_equalsReturnsFalseForDifferentType(): void
    {
        $identifier = new EntityIdentifier('12345');
        $this->assertFalse($identifier->equals($this->createStub(EntityIdentifierInterface::class)));
    }

    /**
     * @throws RandomException
     */
    public function test_equalsReturnsFalseForDifferentConcreteSubclassWithSameValue(): void
    {
        $sharedValue = (string) AggregateId::generate();

        $aggregateId = AggregateId::fromString($sharedValue);
        $eventId = EventId::fromString($sharedValue);

        $this->assertFalse($aggregateId->equals($eventId));
        $this->assertFalse($eventId->equals($aggregateId));
    }

    /**
     * @throws RandomException
     */
    public function test_equalsReturnsTrueForSameConcreteSubclassWithSameValue(): void
    {
        $sharedValue = (string) AggregateId::generate();

        $this->assertTrue(AggregateId::fromString($sharedValue)->equals(AggregateId::fromString($sharedValue)));
    }
}
