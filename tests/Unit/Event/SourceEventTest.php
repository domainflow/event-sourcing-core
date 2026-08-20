<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Event;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventVersion::class)]
#[CoversClass(OccurredOn::class)]
#[CoversClass(SourceEvent::class)]
final class SourceEventTest extends TestCase
{
    public function test_getAggregateId(): void
    {
        $identifier = new DummyEntityIdentifier('agg123');
        $event = new DummySourceEvent($identifier, new DummyEntityIdentifier('evt123'), new DateTimeImmutable('2025-03-21 12:00:00.000000'), EventVersion::fromInt(2));

        $this->assertSame('agg123', $event->getAggregateId()->__toString());
    }

    public function test_getOccurredOnUsesProvidedDateTime(): void
    {
        $identifier = new DummyEntityIdentifier('agg123');
        $date = new DateTimeImmutable('2025-03-21 12:00:00.000000');
        $event = new DummySourceEvent($identifier, new DummyEntityIdentifier('evt123'), $date, EventVersion::fromInt(2));

        $this->assertSame($date, $event->getOccurredOn());
    }

    public function test_getOccurredOnUsesFallbackWhenNull(): void
    {
        $identifier = new DummyEntityIdentifier('agg123');
        $event = new DummySourceEvent($identifier, new DummyEntityIdentifier('evt123'), null, EventVersion::fromInt(2));

        $this->assertInstanceOf(DateTimeImmutable::class, $event->getOccurredOn());

        $now = new DateTimeImmutable();
        $difference = abs($now->getTimestamp() - $event->getOccurredOn()->getTimestamp());

        $this->assertLessThan(2, $difference, 'Fallback DateTimeImmutable should be near current time.');
    }

    public function test_getVersion(): void
    {
        $identifier = new DummyEntityIdentifier('agg123');
        $event = new DummySourceEvent($identifier, new DummyEntityIdentifier('evt123'), new DateTimeImmutable(), EventVersion::fromInt(5));

        $this->assertSame(5, $event->getVersion()->toInt());
    }

    public function test_toArray(): void
    {
        $identifier = new DummyEntityIdentifier('agg123');
        $date = new DateTimeImmutable('2025-03-21 12:00:00.000000');
        $event = new DummySourceEvent($identifier, new DummyEntityIdentifier('evt123'), $date, EventVersion::fromInt(3));
        $array = $event->toArray();

        $this->assertArrayHasKey('aggregateId', $array);
        $this->assertArrayHasKey('eventId', $array);
        $this->assertArrayHasKey('occurredOn', $array);
        $this->assertArrayHasKey('version', $array);
        $this->assertSame('agg123', (string) $array['aggregateId']);
        $this->assertSame('evt123', (string) $array['eventId']);
        $this->assertSame($date->format('Y-m-d H:i:s.u'), $array['occurredOn']);
        $this->assertSame(3, $array['version']);
    }

    public function test_jsonSerialize(): void
    {
        $identifier = new DummyEntityIdentifier('agg123');
        $date = new DateTimeImmutable('2025-03-21 12:00:00.000000');
        $event = new DummySourceEvent($identifier, new DummyEntityIdentifier('evt123'), $date, EventVersion::fromInt(4));
        $this->assertSame($event->toArray(), $event->jsonSerialize());
    }

    public function test_setVersionUpdatesVersionProperty(): void
    {
        $identifier = new DummyEntityIdentifier('agg123');
        $event = new DummySourceEvent($identifier, new DummyEntityIdentifier('evt123'), new DateTimeImmutable(), EventVersion::fromInt(1));

        $event->setVersion(EventVersion::fromInt(42));

        $this->assertSame(42, $event->getVersion()->toInt());
    }

}

# dummy classes
final class DummyEntityIdentifier implements EntityIdentifierInterface
{
    private string $value;

    public function __construct(
        string $value
    ) {
        $this->value = $value;
    }

    public static function fromString(
        string $value
    ): self {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(
        EntityIdentifierInterface $other
    ): bool {
        return $this->value === (string) $other;
    }
}

final class DummySourceEvent extends SourceEvent
{
}
