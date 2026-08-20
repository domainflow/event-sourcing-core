<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Outbox;

use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\OutboxStorageInterface;

/**
 * Reference implementation, and the one the contract test runs against first.
 */
final class InMemoryOutboxStorage implements OutboxStorageInterface
{
    /** @var array<int, array{event: DomainEventInterface, attempts: int, reserved: bool}> */
    private array $entries = [];

    /**
     * @var array<int, array{event: DomainEventInterface, attempts: int}>
     */
    private array $abandoned = [];

    private int $nextId = 1;

    /**
     * @param array<DomainEventInterface> $events
     * @return void
     */
    public function enqueue(
        array $events
    ): void {
        foreach ($events as $event) {
            $this->entries[$this->nextId++] = [
                'event' => $event,
                'attempts' => 0,
                'reserved' => false,
            ];
        }
    }

    /**
     * @param int $limit
     * @return list<OutboxEntry>
     */
    public function reserve(
        int $limit
    ): array {
        $reserved = [];

        foreach ($this->entries as $id => $entry) {
            if (count($reserved) >= $limit) {
                break;
            }

            if ($entry['reserved']) {
                continue;
            }

            $this->entries[$id]['reserved'] = true;
            $reserved[] = new OutboxEntry((string) $id, $entry['event'], $entry['attempts']);
        }

        return $reserved;
    }

    public function markDelivered(
        OutboxEntry $entry
    ): void {
        unset($this->entries[(int) $entry->getId()]);
    }

    public function markFailed(
        OutboxEntry $entry
    ): void {
        $id = (int) $entry->getId();

        if (!isset($this->entries[$id])) {
            return;
        }

        $this->entries[$id]['attempts']++;
        $this->entries[$id]['reserved'] = false;
    }

    public function markAbandoned(
        OutboxEntry $entry
    ): void {
        $id = (int) $entry->getId();

        if (!isset($this->entries[$id])) {
            // Already delivered, or already abandoned. A relay that died
            // between acting and marking retries the whole step, so this has
            // to be a no-op rather than a way to resurrect a delivered entry
            // as a dead letter.
            return;
        }

        $this->abandoned[$id] = [
            'event' => $this->entries[$id]['event'],
            'attempts' => $this->entries[$id]['attempts'],
        ];

        unset($this->entries[$id]);
    }

    /**
     * @param int $limit
     * @return list<OutboxEntry>
     */
    public function retrieveAbandoned(
        int $limit
    ): array {
        $entries = [];

        foreach ($this->abandoned as $id => $entry) {
            if (count($entries) >= $limit) {
                break;
            }

            $entries[] = new OutboxEntry((string) $id, $entry['event'], $entry['attempts']);
        }

        return $entries;
    }

    public function countPending(): int
    {
        return count($this->entries);
    }

    public function countAbandoned(): int
    {
        return count($this->abandoned);
    }
}
