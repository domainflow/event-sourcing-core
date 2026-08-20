<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Operation;

use DomainFlow\EventSourcing\Clock\SystemClock;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\DefaultEventEntryFactory;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventMetadata;
use DomainFlow\EventSourcing\Event\EventPersistenceRecord;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\GlobalEventPage;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\ProjectorInterface;
use DomainFlow\EventSourcing\Operation\RebuildProjection;
use DomainFlow\EventSourcing\Operation\RebuildProjectionResult;
use DomainFlow\EventSourcing\Projector\CatchUpReader;
use DomainFlow\EventSourcing\Storage\InMemoryEventStorage;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use DomainFlow\EventSourcingCore\Provider\Unit\AnotherDummyEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;

/**
 * Rebuilding a projection is not the same operation as catching one up, and
 * saying so in code is half the point of this class: a rebuild starts
 * at the beginning of the stream and throws away what the read model held,
 * while a catch-up resumes from a stored position and keeps it.
 */
#[CoversClass(RebuildProjection::class)]
#[CoversClass(RebuildProjectionResult::class)]
#[UsesClass(CatchUpReader::class)]
#[UsesClass(InMemoryEventStorage::class)]
#[UsesClass(EntityIdentifier::class)]
#[UsesClass(EventVersion::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(DefaultEventEntryFactory::class)]
#[UsesClass(EventEntry::class)]
#[UsesClass(GlobalEventPage::class)]
#[UsesClass(EventId::class)]
#[UsesClass(EventMetadata::class)]
#[UsesClass(EventPersistenceRecord::class)]
#[UsesClass(OccurredOn::class)]
#[UsesTrait(HasEventMetadata::class)]
final class RebuildProjectionTest extends TestCase
{
    public function test_theReadModelIsClearedBeforeAnythingIsReplayed(): void
    {
        $storage = new InMemoryEventStorage();
        $storage->storeEvents([new AnotherDummyEvent(EntityIdentifier::fromString('rebuild'), 1)]);

        $projector = $this->recordingProjector();

        (new RebuildProjection($storage))($projector);

        $this->assertSame(
            ['reset', 'replay'],
            $projector->calls,
            'Replaying onto a read model that still holds the old numbers doubles them.'
        );
    }

    /**
     * The whole stream, from the beginning, whatever any stored position says.
     */
    public function test_itReplaysTheStreamFromItsBeginning(): void
    {
        $storage = new InMemoryEventStorage();
        $storage->storeEvents([
            new AnotherDummyEvent(EntityIdentifier::fromString('rebuild'), 1),
            new AnotherDummyEvent(EntityIdentifier::fromString('rebuild'), 2),
            new AnotherDummyEvent(EntityIdentifier::fromString('rebuild'), 3),
        ]);

        $projector = $this->recordingProjector();

        $result = (new RebuildProjection($storage, pageSize: 2))($projector);

        $this->assertSame(3, $result->getEventsReplayed());
        $this->assertCount(3, $projector->replayed, 'A page size smaller than the stream must not truncate it.');
    }

    /**
     * A projector is asked what it handles, and a rebuild has to respect that:
     * feeding it everything would make `supports()` decorative and hand a read
     * model events it has no rule for.
     */
    public function test_anEventTheProjectorDoesNotSupportIsNotReplayed(): void
    {
        $storage = new InMemoryEventStorage();
        $storage->storeEvents([
            new AnotherDummyEvent(EntityIdentifier::fromString('rebuild'), 1),
            new AnotherDummyEvent(EntityIdentifier::fromString('rebuild'), 2),
        ]);

        $projector = $this->recordingProjector([]);

        $result = (new RebuildProjection($storage))($projector);

        $this->assertSame(0, $result->getEventsReplayed());
        $this->assertSame(['reset'], $projector->calls, 'The stream was read; none of it belonged to this projector.');
    }

    /**
     * The position is returned rather than stored, for the same reason the
     * relay returns a result rather than logging: where a projection's cursor
     * lives is the consumer's decision, and this class has no business
     * inventing a place for it.
     */
    public function test_itReportsThePositionReachedSoTheCallerCanPersistIt(): void
    {
        $storage = new InMemoryEventStorage();
        $storage->storeEvents([
            new AnotherDummyEvent(EntityIdentifier::fromString('rebuild'), 1),
            new AnotherDummyEvent(EntityIdentifier::fromString('rebuild'), 2),
            new AnotherDummyEvent(EntityIdentifier::fromString('rebuild'), 3),
        ]);

        $result = (new RebuildProjection($storage, pageSize: 2))($this->recordingProjector());

        $this->assertNotNull(
            $result->getPosition(),
            'A full page is a stretch handed out long ago, so the reader trusts it and a rebuild can report it.'
        );
    }

    /**
     * The limit, asserted rather than left to be discovered.
     *
     * A stream shorter than one page never leaves the head, where the reader
     * holds its safe position back for the grace period. So a rebuild of a
     * small store reports `null` — meaning "resume from the beginning", which
     * replays events the projection already has. That is the conservative
     * answer: a repeat is something a projector can defend against, a skipped
     * write is not.
     */
    public function test_aRebuildThatNeverLeavesTheHeadReportsNoPositionRatherThanAnUnsafeOne(): void
    {
        $storage = new InMemoryEventStorage();
        $storage->storeEvents([new AnotherDummyEvent(EntityIdentifier::fromString('rebuild'), 1)]);

        $projector = $this->recordingProjector();

        $result = (new RebuildProjection($storage))($projector);

        $this->assertSame(1, $result->getEventsReplayed(), 'The event was still replayed.');
        $this->assertNull($result->getPosition());
    }

    /**
     * An empty store is not an error, and the position that comes back says
     * "nothing yet" rather than a number that would skip the first event ever
     * written.
     */
    public function test_rebuildingFromAnEmptyStoreIsAnAnswerNotAFailure(): void
    {
        $projector = $this->recordingProjector();

        $result = (new RebuildProjection(new InMemoryEventStorage()))($projector);

        $this->assertSame(0, $result->getEventsReplayed());
        $this->assertNull($result->getPosition());
        $this->assertSame(['reset'], $projector->calls);
    }

    /**
     * @param list<class-string>|null $supported
     * @return ProjectorInterface
     */
    private function recordingProjector(
        ?array $supported = null
    ): ProjectorInterface {
        return new class($supported) implements ProjectorInterface {
            /** @var list<string> */
            public array $calls = [];

            /** @var list<DomainEventInterface> */
            public array $replayed = [];

            /**
             * @param list<class-string>|null $supported
             */
            public function __construct(
                private readonly ?array $supported = null
            ) {
            }

            public static function getSubscribedTo(): array
            {
                return [];
            }

            public function handle(
                DomainEventInterface $event
            ): void {
            }

            public function reset(): void
            {
                $this->calls[] = 'reset';
            }

            public function replay(
                DomainEventInterface ...$events
            ): void {
                $this->calls[] = 'replay';
                foreach ($events as $event) {
                    $this->replayed[] = $event;
                }
            }

            public function supports(
                string $eventClass
            ): bool {
                return $this->supported === null || in_array($eventClass, $this->supported, true);
            }

            public function getName(): string
            {
                return 'recording';
            }
        };
    }
}
