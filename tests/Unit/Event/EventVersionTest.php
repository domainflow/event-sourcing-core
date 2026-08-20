<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Event;

use DomainFlow\EventSourcing\Event\EventVersion;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventVersion::class)]
final class EventVersionTest extends TestCase
{
    public function test_constructorThrowsForNegativeVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Event version must be greater or equal than 0');

        new EventVersion(-1);
    }

    public function test_newCreatesVersionOne(): void
    {
        $version = EventVersion::new();

        $this->assertSame(1, $version->toInt());
    }

    public function test_fromIntAndToInt(): void
    {
        $version = EventVersion::fromInt(5);

        $this->assertSame(5, $version->toInt());
    }

    public function test_incrementAddsOne(): void
    {
        $version = EventVersion::fromInt(1)->increment();

        $this->assertSame(2, $version->toInt());
    }

    public function test_addAddsGivenAmount(): void
    {
        $version = EventVersion::fromInt(1)->add(4);

        $this->assertSame(5, $version->toInt());
    }

    public function test_equalsComparesValueNotIdentity(): void
    {
        $this->assertTrue(EventVersion::fromInt(3)->equals(EventVersion::fromInt(3)));
        $this->assertFalse(EventVersion::fromInt(3)->equals(EventVersion::fromInt(4)));
    }

    public function test_jsonSerializeReturnsInt(): void
    {
        $version = EventVersion::fromInt(7);

        $this->assertSame(7, $version->jsonSerialize());
        $this->assertSame('7', json_encode($version));
    }
}
