<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Upcaster;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventFactoryInterface;
use DomainFlow\EventSourcing\Interface\EventUpcasterInterface;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use DomainFlow\EventSourcing\Upcaster\EventUpcasterRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

#[CoversClass(EventVersion::class)]
#[CoversClass(EventUpcasterRegistry::class)]
#[CoversClass(EntityIdentifier::class)]
final class EventUpcasterRegistryTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function test_setFactory(): void
    {
        $factoryMock = $this->createStub(EventFactoryInterface::class);

        $registry = new EventUpcasterRegistry();
        $registry->setFactory($factoryMock);

        $reflection = new ReflectionClass(EventUpcasterRegistry::class);
        $factoryProperty = $reflection->getProperty('factory');

        $this->assertSame($factoryMock, $factoryProperty->getValue($registry));
    }

    /**
     * @throws Exception
     */
    public function test_register_upcaster(): void
    {
        $upcasterMock = $this->createStub(EventUpcasterInterface::class);

        $registry = new EventUpcasterRegistry();
        $registry->register($upcasterMock);

        $reflection = new ReflectionClass(EventUpcasterRegistry::class);
        $upcastersProperty = $reflection->getProperty('upcasters');

        $upcasters = $upcastersProperty->getValue($registry);

        $this->assertCount(1, $upcasters);
        $this->assertSame($upcasterMock, $upcasters[0]);
    }

    /**
     * @throws Exception
     */
    public function test_upcast_with_no_upcasting_needed(): void
    {
        $data = ['aggregateId' => 'agg-123', 'version' => 1];
        $eventType = 'TestEvent';

        $event = new TestEventStub('agg-123', 1);

        $upcasterMock = $this->createStub(EventUpcasterInterface::class);
        $upcasterMock->method('supports')->willReturn(true);
        $upcasterMock->method('upcast')->willReturn($event);

        $factoryMock = $this->createMock(EventFactoryInterface::class);
        $factoryMock->expects($this->once())
            ->method('createFromPayload')
            ->with(TestEventStub::class, ['aggregateId' => 'agg-123', 'version' => 1])

            ->willReturn($event);

        $registry = new EventUpcasterRegistry();
        $registry->setFactory($factoryMock);
        $registry->register($upcasterMock);

        $result = $registry->upcast($eventType, $data);

        $this->assertEquals('agg-123', (string) $result->getAggregateId());
    }

    /**
     * @throws Exception
     */
    public function test_upcast_with_upcasting_needed(): void
    {
        $data = ['aggregateId' => 'agg-123', 'version' => 1];
        $eventType = 'TestEvent';

        $upcastedEvent = new class() implements DomainEventInterface {
            use HasEventMetadata;

            protected EventVersion $version;
            public function getAggregateId(): EntityIdentifier
            {
                return new EntityIdentifier('agg-123');
            }
            public function getOccurredOn(): DateTimeImmutable
            {
                return new DateTimeImmutable();
            }
            public function getVersion(): EventVersion
            {
                return EventVersion::fromInt(2);
            }
            public function toArray(): array
            {
                return ['aggregateId' => 'agg-123', 'version' => 2];
            }

            public function setVersion(
                EventVersion $version
            ): void {
                $this->version = $version;
            }
        };

        $upcasterMock = $this->createStub(EventUpcasterInterface::class);
        $upcasterMock->method('supports')->willReturn(true);
        $upcasterMock->method('upcast')->willReturn($upcastedEvent);

        $factoryMock = $this->createMock(EventFactoryInterface::class);
        $factoryMock->expects($this->once())
            ->method('createFromPayload')
            ->with($upcastedEvent::class, ['aggregateId' => 'agg-123', 'version' => 2])
            ->willReturn($upcastedEvent);

        $registry = new EventUpcasterRegistry();
        $registry->setFactory($factoryMock);
        $registry->register($upcasterMock);

        $result = $registry->upcast($eventType, $data);

        $this->assertEquals(2, $result->getVersion()->toInt());
        $this->assertEquals('agg-123', (string) $result->getAggregateId());
    }

    /**
     * @throws Exception
     */
    public function test_upcast_with_factory(): void
    {
        $data = ['aggregateId' => 'agg-123', 'version' => 1];
        $eventType = 'TestEvent';

        $upcasterMock = $this->createStub(EventUpcasterInterface::class);
        $upcasterMock->method('supports')->willReturn(false);

        $factoryMock = $this->createStub(EventFactoryInterface::class);
        $factoryMock->method('createFromPayload')->willReturn(new class() implements DomainEventInterface {
            use HasEventMetadata;

            protected EventVersion $version;

            public function getAggregateId(): EntityIdentifier
            {
                return new EntityIdentifier('agg-123');
            }
            public function getOccurredOn(): DateTimeImmutable
            {
                return new DateTimeImmutable();
            }
            public function getVersion(): EventVersion
            {
                return EventVersion::fromInt(1);
            }
            public function toArray(): array
            {
                return ['aggregateId' => 'agg-123', 'version' => 1];
            }

            public function setVersion(
                EventVersion $version
            ): void {
                $this->version = $version;
            }
        });

        $registry = new EventUpcasterRegistry();
        $registry->setFactory($factoryMock);
        $registry->register($upcasterMock);

        $result = $registry->upcast($eventType, $data);

        $this->assertEquals('agg-123', $result->getAggregateId());
    }

    /**
     * @throws Exception
     */
    public function test_upcast_throws_runtime_exception_if_no_factory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Event factory is not set for EventUpcasterRegistry.');

        $data = ['aggregateId' => 'agg-123', 'version' => 1];
        $eventType = 'TestEvent';

        $upcasterMock = $this->createStub(EventUpcasterInterface::class);
        $upcasterMock->method('supports')->willReturn(false);

        $registry = new EventUpcasterRegistry();
        $registry->register($upcasterMock);

        $registry->upcast($eventType, $data);
    }

    /**
     * @throws Exception
     */
    public function test_supports(): void
    {
        $upcasterMock = $this->createStub(EventUpcasterInterface::class);
        $upcasterMock->method('supports')->willReturn(true);

        $registry = new EventUpcasterRegistry();
        $registry->register($upcasterMock);

        $this->assertTrue($registry->supports('TestEvent'));
    }

    /**
     * @throws Exception
     */
    public function test_supports_returns_false_if_no_upcaster_supports_event(): void
    {
        $upcasterMock = $this->createStub(EventUpcasterInterface::class);
        $upcasterMock->method('supports')->willReturn(false);

        $registry = new EventUpcasterRegistry();
        $registry->register($upcasterMock);

        $this->assertFalse($registry->supports('TestEvent'));
    }

    /**
     * An upcaster whose output is not stable — one that regenerates a
     * timestamp on every call, say — reports a change on every pass, so the
     * chain never settles. Without a cap the worker simply stops responding:
     * no error, no log line, nothing pointing at the upcaster.
     */
    public function test_anUpcasterThatNeverSettlesFailsInsteadOfLoopingForever(): void
    {
        $registry = new EventUpcasterRegistry();
        $registry->register(new NeverSettlingUpcaster());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not settle');

        $registry->upcast(RestlessEvent::class, ['value' => 'start']);
    }
}

// dummy class
final class TestEventStub implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public function __construct(
        private readonly string $aggregateId,
        int $version
    ) {
        $this->version = EventVersion::fromInt($version);
    }

    public function getAggregateId(): EntityIdentifier
    {
        return new EntityIdentifier($this->aggregateId);
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
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
        ];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class NeverSettlingUpcaster implements EventUpcasterInterface
{
    public function supports(
        string $eventClass
    ): bool {
        return $eventClass === RestlessEvent::class;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upcast(
        string $eventType,
        array $data
    ): DomainEventInterface {
        // A different payload every time, which is what an upcaster
        // regenerating occurredOn does without meaning to.
        return new RestlessEvent();
    }
}

final class RestlessEvent implements DomainEventInterface
{
    use HasEventMetadata;

    private static int $counter = 0;

    private readonly int $tick;

    public function __construct()
    {
        $this->tick = ++self::$counter;
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString('restless');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-18 10:00:00');
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return ['tick' => $this->tick];
    }

    public function setVersion(
        EventVersion $version
    ): void {
    }
}
