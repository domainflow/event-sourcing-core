<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Integration;

use DomainFlow\EventSourcing\Interface\ProjectorInterface;
use DomainFlow\EventSourcingCore\Provider\Integration\ProjectionReplayIntegrationTestCase;
use DomainFlow\EventSourcingCore\Tests\Integration\Repository\InMemoryReplayProjector;
use DomainFlow\EventSourcingCore\Tests\Setup\InMemorySetup;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing()]
final class ProjectionReplayIntegrationTest extends ProjectionReplayIntegrationTestCase
{
    use InMemorySetup;

    private InMemoryReplayProjector $projector;

    protected function setupCounterProjections(): void
    {
        $this->projector = new InMemoryReplayProjector();
    }

    protected function getCounterProjectionRepository(): ProjectorInterface
    {
        return $this->projector;
    }

    protected function getProjectionCounter(string $aggregateId): ?int
    {
        return $this->projector->getCounter($aggregateId);
    }

    protected function projectionRowExists(string $aggregateId): bool
    {
        return $this->projector->rowExists($aggregateId);
    }

    protected function getAllProjectionRows(): array
    {
        return $this->projector->all();
    }
}
