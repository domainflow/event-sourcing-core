<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

use DomainFlow\EventSourcing\Event\EventVersion;

interface SnapshotFactoryInterface
{
    /**
     * Build a snapshot from an aggregate's stored state.
     *
     * The aggregate id and version are passed explicitly rather than being
     * left for the implementation to dig out of $state by convention: getting
     * the version wrong makes AggregateRepository replay events the snapshot
     * already accounts for, which silently double-applies them.
     *
     * @param class-string<SnapshotInterface> $snapshotClass
     * @param EntityIdentifierInterface $aggregateId
     * @param EventVersion $version
     * @param array<string, mixed> $state
     * @return SnapshotInterface
     */
    public function createFromStorage(
        string $snapshotClass,
        EntityIdentifierInterface $aggregateId,
        EventVersion $version,
        array $state
    ): SnapshotInterface;
}
