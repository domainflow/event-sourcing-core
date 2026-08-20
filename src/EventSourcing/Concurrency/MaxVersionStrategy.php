<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Concurrency;

use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Exception\ConcurrencyException;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;

final class MaxVersionStrategy implements ConcurrencyCheckStrategyInterface
{
    /**
     * Asserts that a batch continues every stream it touches without a gap.
     *
     * A batch may carry events for any number of aggregates because the call
     * is the unit of the write, and each aggregate's events must form a
     * contiguous run starting one past where its stream currently stands.
     *
     * Every event must be checked: a batch spanning aggregates A and B cannot
     * verify A while waving B through, and a run of [4, 5, 7] on a stream at 3
     * lined up at its first
     * event with nobody looking at the gap. Neither was silent data loss — the
     * unique index in every persistent adapter and the batch guard in
     * InMemoryEventStorage still refuse the write — but the refusal arrived
     * from the storage layer wearing a driver's shape, from the decorator whose
     * entire job is to catch it first and name it properly.
     *
     * @param DomainEventInterface[] $events
     * @param EventStorageInterface $inner
     * @throws ConcurrencyException
     * @return void
     */
    public function assertNoConflict(
        array $events,
        EventStorageInterface $inner
    ): void {
        foreach ($this->groupByAggregate($events) as $group) {
            $this->assertGroupContinuesItsStream($group, $inner);
        }
    }

    /**
     * Grouped by the string form of the id, keeping one identifier object per
     * group so the storage is asked with what the caller passed rather than
     * with something reconstructed from a string.
     *
     * @param DomainEventInterface[] $events
     * @return array<string, array{id: EntityIdentifierInterface, versions: non-empty-list<int>}>
     */
    private function groupByAggregate(
        array $events
    ): array {
        $grouped = [];

        foreach ($events as $event) {
            $aggregateId = $event->getAggregateId();
            $key = (string) $aggregateId;

            $grouped[$key]['id'] = $aggregateId;
            $grouped[$key]['versions'][] = $event->getVersion()->toInt();
        }

        return $grouped;
    }

    /**
     * One getCurrentMaxVersion() call per aggregate, not per event. That is the
     * honest cost of checking the whole batch; anything more would make the
     * write expensive in the name of protecting it.
     *
     * The versions are sorted first, because what this owns is whether the
     * batch forms a contiguous run — not the order the caller handed the
     * events over in. A repository that collects uncommitted events from two
     * aggregates interleaves them by construction, and rejecting that would be
     * rejecting a correct write for being untidy.
     *
     * @param array{id: EntityIdentifierInterface, versions: non-empty-list<int>} $group
     * @throws ConcurrencyException
     */
    private function assertGroupContinuesItsStream(
        array $group,
        EventStorageInterface $inner
    ): void {
        $versions = $group['versions'];
        sort($versions);

        $expected = $inner->getCurrentMaxVersion($group['id'])->increment()->toInt();

        foreach ($versions as $version) {
            if ($version !== $expected) {
                throw new ConcurrencyException(sprintf(
                    'Concurrency conflict: expected version %d, got %d for aggregate %s',
                    $expected,
                    $version,
                    $group['id']
                ));
            }

            $expected = EventVersion::fromInt($version)->increment()->toInt();
        }
    }
}
