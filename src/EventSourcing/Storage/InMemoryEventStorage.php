<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Storage;

use DomainFlow\EventSourcing\Event\DefaultEventEntryFactory;
use DomainFlow\EventSourcing\Event\EventPersistenceRecord;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\GlobalEventPage;
use DomainFlow\EventSourcing\Exception\ConcurrencyException;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventEntryFactoryInterface;
use DomainFlow\EventSourcing\Interface\EventFactoryInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use JsonException;
use Random\RandomException;
use ReflectionException;

class InMemoryEventStorage implements EventStorageInterface
{
    /**
     * The whole store, in the order events were appended, keyed by global
     * position.
     *
     * One structure rather than a per-aggregate map beside a global one: the
     * per-aggregate view is a filter over this, and two structures holding the
     * same facts can only ever drift apart.
     *
     * @var array<int, array{aggregateId: string, record: EventPersistenceRecord}>
     */
    private array $log = [];

    private int $nextPosition = 1;

    private EventEntryFactoryInterface $entryFactory;

    public function __construct(
        ?EventEntryFactoryInterface $entryFactory = null,
        ?EventFactoryInterface $eventFactory = null
    ) {
        $this->entryFactory = $entryFactory ?? new DefaultEventEntryFactory($eventFactory);
    }

    /**
     * Store a batch of events.
     *
     * @param array<DomainEventInterface> $events
     * @throws JsonException|RandomException
     * @return void
     */
    public function storeEvents(
        array $events
    ): void {
        /** @var array<string, array<int, EventPersistenceRecord>> $staged */
        $staged = [];

        foreach ($events as $event) {
            $aggregateId = (string) $event->getAggregateId();
            $version = $event->getVersion()->toInt();

            if (isset($staged[$aggregateId][$version]) || $this->hasVersion($aggregateId, $version)) {
                throw new ConcurrencyException(sprintf(
                    'Event version %d for aggregate %s already exists.',
                    $version,
                    $aggregateId
                ));
            }

            $staged[$aggregateId][$version] = $this->entryFactory->createFromDomainEvent($event);
        }

        foreach ($staged as $aggregateId => $records) {
            foreach ($records as $record) {
                $this->log[$this->nextPosition++] = [
                    'aggregateId' => $aggregateId,
                    'record' => $record,
                ];
            }
        }
    }

    /**
     * Whether a version is already stored for an aggregate.
     *
     * @param string $aggregateId
     * @param int $version
     * @return bool
     */
    private function hasVersion(
        string $aggregateId,
        int $version
    ): bool {
        foreach ($this->recordsOf($aggregateId) as $record) {
            if ($this->versionOf($record) === $version) {
                return true;
            }
        }

        return false;
    }

    /**
     * An aggregate's records, in the order they were appended.
     *
     * @param string $aggregateId
     * @return array<int, EventPersistenceRecord>
     */
    private function recordsOf(
        string $aggregateId
    ): array {
        $records = [];

        foreach ($this->log as $position => $entry) {
            if ($entry['aggregateId'] === $aggregateId) {
                $records[$position] = $entry['record'];
            }
        }

        return $records;
    }

    /**
     * A stored record's version, or 0 when it carries none — 0 is the
     * unassigned sentinel and never a real position in a stream, so it sorts
     * and compares harmlessly.
     *
     * @param EventPersistenceRecord $record
     * @return int
     */
    private function versionOf(
        EventPersistenceRecord $record
    ): int {
        $version = $record->toArray()['version'] ?? null;

        return is_numeric($version) ? (int) $version : 0;
    }

    /**
     * Retrieve events for a specific aggregate, in version order.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @throws ReflectionException
     * @return array<DomainEventInterface>
     */
    public function retrieveEvents(
        EntityIdentifierInterface $aggregateId
    ): array {
        return $this->hydrateInVersionOrder($this->recordsOf((string) $aggregateId));
    }

    /**
     * Retrieve an aggregate's events newer than a given version.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @param EventVersion $afterVersion
     * @throws ReflectionException
     * @return array<DomainEventInterface>
     */
    public function retrieveEventsFromVersion(
        EntityIdentifierInterface $aggregateId,
        EventVersion $afterVersion
    ): array {
        $bound = $afterVersion->toInt();

        return $this->hydrateInVersionOrder(array_filter(
            $this->recordsOf((string) $aggregateId),
            fn (EventPersistenceRecord $record): bool => $this->versionOf($record) > $bound
        ));
    }

    /**
     * @param array<int, EventPersistenceRecord> $records
     * @throws ReflectionException
     * @return array<DomainEventInterface>
     */
    private function hydrateInVersionOrder(
        array $records
    ): array {
        $ordered = array_values($records);

        usort(
            $ordered,
            fn (EventPersistenceRecord $a, EventPersistenceRecord $b): int => $this->versionOf($a) <=> $this->versionOf($b)
        );

        return array_map(
            fn (EventPersistenceRecord $record) => $this->entryFactory->recordToDomainEvent($record),
            $ordered
        );
    }

    /**
     * Retrieve all events, in the order they were appended.
     *
     * @throws ReflectionException
     * @return iterable<DomainEventInterface>
     */
    public function retrieveAllEvents(): iterable
    {
        foreach ($this->log as $entry) {
            yield $this->entryFactory->recordToDomainEvent($entry['record']);
        }
    }

    /**
     * Read the global stream from a position.
     *
     * The position is the log key, which only ever grows: an event appended
     * between two reads lands after everything already handed out, so a reader
     * resuming from its last position can neither skip it nor see it twice.
     *
     * @param string|null $afterPosition
     * @param int $limit
     * @throws ReflectionException
     * @return GlobalEventPage
     */
    public function retrieveEventsFromPosition(
        ?string $afterPosition,
        int $limit
    ): GlobalEventPage {
        $after = $afterPosition === null ? 0 : (int) $afterPosition;

        $events = [];
        $position = $afterPosition;

        foreach ($this->log as $key => $entry) {
            if ($key <= $after || count($events) >= $limit) {
                continue;
            }

            $events[] = $this->entryFactory->recordToDomainEvent($entry['record']);
            $position = (string) $key;
        }

        return new GlobalEventPage($events, $position);
    }

    /**
     * Delete all events for a specific aggregate.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return void
     */
    public function deleteEvents(
        EntityIdentifierInterface $aggregateId
    ): void {
        foreach (array_keys($this->recordsOf((string) $aggregateId)) as $position) {
            unset($this->log[$position]);
        }
    }

    /**
     * Retrieve a paginated list of events.
     *
     * @deprecated See EventStorageInterface::retrievePaginatedEvents().
     * @param int|null $offset
     * @param int|null $limit
     * @throws ReflectionException
     * @return array<DomainEventInterface>
     */
    public function retrievePaginatedEvents(
        ?int $offset = 0,
        ?int $limit = 100
    ): array {
        return array_slice(iterator_to_array($this->retrieveAllEvents(), false), $offset ?? 0, $limit ?? 100);
    }

    /**
     * Retrieve the current max version for an aggregate.
     *
     * @param EntityIdentifierInterface $aggregateId
     * @return EventVersion
     */
    public function getCurrentMaxVersion(
        EntityIdentifierInterface $aggregateId
    ): EventVersion {
        $records = $this->recordsOf((string) $aggregateId);

        if ($records === []) {
            return EventVersion::unassigned();
        }

        return EventVersion::fromInt(max(array_map(
            fn (EventPersistenceRecord $record): int => $this->versionOf($record),
            $records
        )));
    }
}
