<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Event;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\DefaultEventEntryFactory;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventMetadata;
use DomainFlow\EventSourcing\Event\EventPersistenceRecord;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventFactoryInterface;
use DomainFlow\EventSourcing\Storage\InMemoryEventStorage;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Two stores in one process, each with its own way of rebuilding
 * events.
 *
 * The factory and upcaster used to live in statics on EventEntry that every
 * storage constructor wrote into, so wiring two stores in one service — two
 * bounded contexts, or MySQL for events beside Redis for something else —
 * meant the second one silently disarmed the first. Which store won depended
 * on the order the container happened to build them in, and the symptom was
 * either a reconstruction failure or a quietly wrong event.
 *
 * These assert the property both ways round, because construction order was
 * the whole problem.
 */
#[CoversClass(EventEntry::class)]
#[CoversClass(DefaultEventEntryFactory::class)]
#[UsesClass(InMemoryEventStorage::class)]
#[UsesClass(EventPersistenceRecord::class)]
#[UsesClass(EntityIdentifier::class)]
#[UsesClass(EventId::class)]
#[UsesClass(EventVersion::class)]
#[UsesClass(OccurredOn::class)]
#[UsesClass(EventMetadata::class)]
#[UsesTrait(HasEventMetadata::class)]
final class EventReconstructionOwnershipTest extends TestCase
{
    public function test_twoStoresKeepTheirOwnFactories_firstBuiltFirst(): void
    {
        $red = $this->storageRebuildingAs('red');
        $blue = $this->storageRebuildingAs('blue');

        $this->assertRebuildsAs('red', $red);
        $this->assertRebuildsAs('blue', $blue);
    }

    public function test_twoStoresKeepTheirOwnFactories_secondBuiltFirst(): void
    {
        $blue = $this->storageRebuildingAs('blue');
        $red = $this->storageRebuildingAs('red');

        $this->assertRebuildsAs('red', $red);
        $this->assertRebuildsAs('blue', $blue);
    }

    private function assertRebuildsAs(
        string $expected,
        InMemoryEventStorage $storage
    ): void {
        $aggregateId = EntityIdentifier::fromString('ownership-' . $expected);

        $storage->storeEvents([new PlainEvent($aggregateId, 1)]);

        $events = iterator_to_array($storage->retrieveAllEvents(), false);

        $this->assertCount(1, $events);
        $this->assertInstanceOf(TaggedEvent::class, $events[0]);
        $this->assertSame(
            $expected,
            $events[0]->tag,
            'Each store must rebuild through the factory it was given, whichever was constructed last.'
        );
    }

    private function storageRebuildingAs(
        string $tag
    ): InMemoryEventStorage {
        return new InMemoryEventStorage(new DefaultEventEntryFactory(new TaggingEventFactory($tag)));
    }
}

final class TaggingEventFactory implements EventFactoryInterface
{
    public function __construct(
        private readonly string $tag
    ) {
    }

    public function supports(
        string $eventClass
    ): bool {
        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createFromPayload(
        string $eventClass,
        array $payload
    ): DomainEventInterface {
        return new TaggedEvent($this->tag);
    }
}

final class TaggedEvent implements DomainEventInterface
{
    use HasEventMetadata;

    public function __construct(
        public readonly string $tag
    ) {
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString('tagged');
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
        return ['tag' => $this->tag];
    }

    public function setVersion(
        EventVersion $version
    ): void {
    }
}

final class PlainEvent implements DomainEventInterface
{
    use HasEventMetadata;

    private EventVersion $version;

    public function __construct(
        private readonly EntityIdentifierInterface $aggregateId,
        int $version
    ) {
        $this->version = EventVersion::fromInt($version);
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->aggregateId;
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-18 10:00:00');
    }

    public function getVersion(): EventVersion
    {
        return $this->version;
    }

    public function toArray(): array
    {
        return ['aggregateId' => (string) $this->aggregateId, 'version' => $this->version->toInt()];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}
