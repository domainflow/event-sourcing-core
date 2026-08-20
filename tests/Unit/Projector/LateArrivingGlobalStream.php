<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Projector;

use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\GlobalEventPage;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use LogicException;

/**
 * A global stream whose events can be made visible out of position order.
 *
 * That is the whole point: no real adapter can be asked to produce this
 * interleaving deterministically, because it depends on two writes overlapping
 * in time. Here a test simply adds an event at a position below one it has
 * already handed out, which is exactly what a transaction committing late
 * looks like from a reader's side.
 */
final class LateArrivingGlobalStream implements EventStorageInterface
{
    /** @var array<int, DomainEventInterface> */
    private array $visible = [];

    public function becomesVisible(
        int $position,
        DomainEventInterface $event
    ): void {
        $this->visible[$position] = $event;
        ksort($this->visible);
    }

    public function retrieveEventsFromPosition(
        ?string $afterPosition,
        int $limit
    ): GlobalEventPage {
        $after = $afterPosition === null ? 0 : (int) $afterPosition;

        $events = [];
        $position = $afterPosition;

        foreach ($this->visible as $key => $event) {
            if ($key <= $after || count($events) >= $limit) {
                continue;
            }

            $events[] = $event;
            $position = (string) $key;
        }

        return new GlobalEventPage($events, $position);
    }

    public function storeEvents(
        array $events
    ): void {
        throw new LogicException('Not part of what this double is for.');
    }

    public function retrieveEvents(
        EntityIdentifierInterface $aggregateId
    ): array {
        throw new LogicException('Not part of what this double is for.');
    }

    public function retrieveEventsFromVersion(
        EntityIdentifierInterface $aggregateId,
        EventVersion $afterVersion
    ): array {
        throw new LogicException('Not part of what this double is for.');
    }

    public function retrieveAllEvents(): iterable
    {
        throw new LogicException('Not part of what this double is for.');
    }

    public function deleteEvents(
        EntityIdentifierInterface $aggregateId
    ): void {
        throw new LogicException('Not part of what this double is for.');
    }

    public function retrievePaginatedEvents(
        ?int $offset,
        ?int $limit
    ): array {
        throw new LogicException('Not part of what this double is for.');
    }

    public function getCurrentMaxVersion(
        EntityIdentifierInterface $aggregateId
    ): EventVersion {
        throw new LogicException('Not part of what this double is for.');
    }
}
