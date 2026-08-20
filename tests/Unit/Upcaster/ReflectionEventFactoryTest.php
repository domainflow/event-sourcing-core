<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Upcaster;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use DomainFlow\EventSourcing\Upcaster\ReflectionEventFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use RuntimeException;

#[CoversClass(ReflectionEventFactory::class)]
#[CoversClass(EventVersion::class)]
#[CoversClass(EntityIdentifier::class)]
#[UsesClass(OccurredOn::class)]
final class ReflectionEventFactoryTest extends TestCase
{
    /**
     * @throws ReflectionException|Exception|DateMalformedStringException
     */
    public function test_createFromPayload_success(): void
    {
        $factory = new ReflectionEventFactory();

        $aggregateIdMock = $this->createStub(EntityIdentifierInterface::class);
        $aggregateIdMock->method('__toString')->willReturn('agg-123');

        $payload = [
            'aggregateId' => $aggregateIdMock,
            'occurredOn' => new DateTimeImmutable('2025-01-01 00:00:00.000000'),
            'version' => EventVersion::fromInt(2),
        ];

        $event = $factory->createFromPayload(TestReflectionEvent::class, $payload);

        $this->assertSame('agg-123', (string) $event->getAggregateId());
        $this->assertEquals(2, $event->getVersion()->toInt());
        $this->assertInstanceOf(DateTimeImmutable::class, $event->getOccurredOn());
    }

    /**
     * @throws ReflectionException|Exception|DateMalformedStringException
     */
    public function test_createFromPayload_converts_string_values_to_typed_objects(): void
    {
        $factory = new ReflectionEventFactory();

        $payload = [
            'aggregateId' => 'agg-456',
            'occurredOn' => '2025-02-02 00:00:00.000000',
            'version' => EventVersion::fromInt(3),
        ];

        $event = $factory->createFromPayload(TestReflectionEvent::class, $payload);

        $this->assertSame('agg-456', (string) $event->getAggregateId());
        $this->assertSame('2025-02-02 00:00:00.000000', $event->getOccurredOn()->format('Y-m-d H:i:s.u'));
    }

    /**
     * @throws ReflectionException|Exception|DateMalformedStringException
     */
    public function test_createFromPayload_converts_numeric_version_to_EventVersion(): void
    {
        $factory = new ReflectionEventFactory();

        $payload = [
            'aggregateId' => 'agg-789',
            'occurredOn' => '2025-03-03 00:00:00.000000',
            'version' => 5,
        ];

        $event = $factory->createFromPayload(TestReflectionEvent::class, $payload);

        $this->assertInstanceOf(EventVersion::class, $event->getVersion());
        $this->assertSame(5, $event->getVersion()->toInt());
    }

    /**
     * @throws ReflectionException|DateMalformedStringException
     */
    public function test_createFromPayload_throws_if_class_not_exist(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Event class NonExistentClass does not exist.");

        $factory = new ReflectionEventFactory();
        $factory->createFromPayload('NonExistentClass', []);
    }

    /**
     * @throws ReflectionException|DateMalformedStringException
     */
    public function test_createFromPayload_throws_if_no_constructor(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Event class " . NoConstructorEvent::class . " has no constructor.");

        $factory = new ReflectionEventFactory();
        $factory->createFromPayload(NoConstructorEvent::class, []);
    }

    /**
     * @throws ReflectionException|DateMalformedStringException
     */
    public function test_createFromPayload_throws_if_missing_required_field(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Missing required field 'aggregateId' for event " . TestReflectionEvent::class);

        $factory = new ReflectionEventFactory();
        $factory->createFromPayload(TestReflectionEvent::class, [
            'version' => 1,
            'occurredOn' => (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
    }

    /**
     * @throws ReflectionException|DateMalformedStringException
     */
    public function test_createFromPayload_throws_if_not_instanceof_DomainEventInterface(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Event instance is not a DomainEventInterface.");

        $factory = new ReflectionEventFactory();
        $factory->createFromPayload(InvalidEvent::class, []);
    }

    /**
     * This is the other hydration path — and the one the adapters' own tests
     * wire up. The
     * stored format carries no offset, so parsing it in whatever
     * `date.timezone` the process happens to have moves the instant: an event
     * written by a UTC service and read by a Berlin one denoted a different
     * moment, and drifted again every time it was read and written back.
     *
     * The storage contract never caught it because every binding builds its
     * storage *without* an event factory, so this path was never on it.
     *
     * @throws ReflectionException|Exception
     */
    public function test_a_stored_timestamp_is_read_as_utc_whatever_the_runtime(): void
    {
        $runtimeTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('Europe/Berlin');

            $event = (new ReflectionEventFactory())->createFromPayload(TestReflectionEvent::class, [
                'aggregateId' => 'agg-1',
                'version' => 1,
                'occurredOn' => '2026-08-18 21:18:42.370318',
            ]);

            $this->assertSame(
                (new DateTimeImmutable('2026-08-18 21:18:42.370318', new DateTimeZone('UTC')))->getTimestamp(),
                $event->getOccurredOn()->getTimestamp(),
                'The stored value was read as local time and moved.'
            );
        } finally {
            date_default_timezone_set($runtimeTimezone);
        }
    }

    /**
     * The null-coalescing operator cannot tell "no such key" from "the key is
     * null", so a stored null came
     * back as the constructor's default — a value that was never written.
     *
     * @throws ReflectionException|Exception
     */
    public function test_a_stored_null_is_not_replaced_by_the_parameter_default(): void
    {
        $event = (new ReflectionEventFactory())->createFromPayload(EventWithADefault::class, [
            'aggregateId' => 'agg-1',
            'version' => 1,
            'label' => null,
        ]);

        $this->assertInstanceOf(EventWithADefault::class, $event);
        $this->assertNull($event->label, 'A stored null was replaced by the signature default.');
    }

    /**
     * The other half of the same distinction: a key that really is absent does
     * still fall back to the default.
     *
     * @throws ReflectionException|Exception
     */
    public function test_an_absent_field_still_falls_back_to_the_default(): void
    {
        $event = (new ReflectionEventFactory())->createFromPayload(EventWithADefault::class, [
            'aggregateId' => 'agg-1',
            'version' => 1,
        ]);

        $this->assertInstanceOf(EventWithADefault::class, $event);
        $this->assertSame('fallback', $event->label);
    }
}

// dummy classes
final class EventWithADefault implements DomainEventInterface
{
    use HasEventMetadata;

    public function __construct(
        private readonly EntityIdentifierInterface $aggregateId,
        protected EventVersion $version,
        public readonly ?string $label = 'fallback'
    ) {
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->aggregateId;
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }

    public function toArray(): array
    {
        return ['label' => $this->label];
    }
}

final class TestReflectionEvent implements DomainEventInterface
{
    use HasEventMetadata;

    public function __construct(
        private readonly EntityIdentifierInterface $aggregateId,
        protected EventVersion $version,
        private readonly ?DateTimeImmutable $occurredOn = null
    ) {
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->aggregateId;
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return $this->occurredOn ?? new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    public function toArray(): array
    {
        return [
            'aggregateId' => (string) $this->aggregateId,
            'version' => $this->version,
            'occurredOn' => $this->getOccurredOn()->format('Y-m-d H:i:s.u'),
        ];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class NoConstructorEvent
{
}

final class InvalidEvent
{
    public function __construct()
    {
    }
}
