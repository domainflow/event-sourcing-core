<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Snapshot;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshot;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshotFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(GenericSnapshotFactory::class)]
#[UsesClass(GenericSnapshot::class)]
#[UsesClass(EntityIdentifier::class)]
#[UsesClass(EventVersion::class)]
#[UsesClass(OccurredOn::class)]
final class GenericSnapshotFactoryTest extends TestCase
{
    private function aggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString('agg-1');
    }

    public function test_buildsAGenericSnapshotFromTheGivenIdVersionAndState(): void
    {
        $snapshot = (new GenericSnapshotFactory())->createFromStorage(
            GenericSnapshot::class,
            $this->aggregateId(),
            EventVersion::fromInt(7),
            ['balance' => 42]
        );

        $this->assertInstanceOf(GenericSnapshot::class, $snapshot);
        $this->assertSame('agg-1', (string) $snapshot->getAggregateId());
        $this->assertSame(7, $snapshot->getVersion()->toInt());
        $this->assertSame(['balance' => 42], $snapshot->getState());
    }

    public function test_buildsACustomSnapshotClassWithTheSameConstructorShape(): void
    {
        $snapshot = (new GenericSnapshotFactory())->createFromStorage(
            CustomShapedSnapshot::class,
            $this->aggregateId(),
            EventVersion::fromInt(3),
            ['balance' => 1]
        );

        $this->assertInstanceOf(CustomShapedSnapshot::class, $snapshot);
        $this->assertSame(3, $snapshot->getVersion()->toInt());
    }

    public function test_rejectsAClassThatIsNotASnapshot(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement SnapshotInterface');

        /** @phpstan-ignore-next-line intentionally invalid class for this assertion */
        (new GenericSnapshotFactory())->createFromStorage(
            stdClass::class,
            $this->aggregateId(),
            EventVersion::fromInt(1),
            []
        );
    }

    public function test_rejectsASnapshotClassItCannotBuild(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('register a SnapshotFactoryInterface for it');

        (new GenericSnapshotFactory())->createFromStorage(
            DifferentlyShapedSnapshot::class,
            $this->aggregateId(),
            EventVersion::fromInt(1),
            []
        );
    }
}

final class CustomShapedSnapshot implements SnapshotInterface
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        private readonly EntityIdentifierInterface $aggregateId,
        private readonly EventVersion $version,
        private readonly array $state,
        private readonly OccurredOn $occurredOn
    ) {
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->aggregateId;
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        return $this->state;
    }

    public function getOccurredOn(): OccurredOn
    {
        return $this->occurredOn;
    }
}

/**
 * A realistic shape the default factory cannot build: a private constructor
 * behind a named constructor, as many value objects are written.
 */
final class DifferentlyShapedSnapshot implements SnapshotInterface
{
    private function __construct(
        private readonly string $onlyThis
    ) {
    }

    public static function create(
        string $value
    ): self {
        return new self($value);
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString($this->onlyThis);
    }

    public function getVersion(): EventVersion
    {
        return EventVersion::unassigned();
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        return [];
    }

    public function getOccurredOn(): OccurredOn
    {
        return OccurredOn::now();
    }
}
