<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Aggregate;

use DomainFlow\EventSourcing\Aggregate\AggregateId;
use DomainFlow\Uuid\UuidV6;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

#[CoversClass(AggregateId::class)]
final class AggregateIdTest extends TestCase
{
    public function test_fromStringCreatesAggregateIdMatchingGivenUuid(): void
    {
        $uuid = (string) UuidV6::generate();

        $aggregateId = AggregateId::fromString($uuid);

        $this->assertSame($uuid, (string) $aggregateId);
    }

    /**
     * @throws RandomException
     */
    public function test_generateCreatesAValidAggregateId(): void
    {
        $aggregateId = AggregateId::generate();

        $this->assertTrue(UuidV6::isValid((string) $aggregateId));
    }

    public function test_toUuidReturnsEquivalentUuidV6(): void
    {
        $uuid = (string) UuidV6::generate();
        $aggregateId = AggregateId::fromString($uuid);

        $this->assertSame($uuid, (string) $aggregateId->toUuid());
    }
}
