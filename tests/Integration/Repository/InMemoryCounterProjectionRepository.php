<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Integration\Repository;

use DomainFlow\EventSourcingCore\Provider\Integration\CounterProjectionRepositoryInterface;

final class InMemoryCounterProjectionRepository implements CounterProjectionRepositoryInterface
{
    /**
     * @var array<string, int>
     */
    private array $store = [];

    public function getCounter(
        string $aggregateId
    ): ?int {
        return $this->store[$aggregateId] ?? null;
    }

    public function saveCounter(
        string $aggregateId,
        int $counter
    ): void {
        $this->store[$aggregateId] = $counter;
    }

    public function reset(): void
    {
        $this->store = [];
    }

    public function all(): array
    {
        return array_map(
            fn ($counter, $id) => ['aggregateId' => $id, 'counter' => $counter],
            $this->store,
            array_keys($this->store)
        );
    }

    public function rowExists(
        string $aggregateId
    ): bool {
        return array_key_exists($aggregateId, $this->store);
    }
}
