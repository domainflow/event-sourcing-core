<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Integration;

use DomainFlow\EventSourcingCore\Provider\Integration\ProjectionAndReadModelIntegrationTestCase;
use DomainFlow\EventSourcingCore\Tests\Integration\Repository\InMemoryCounterProjectionRepository;
use DomainFlow\EventSourcingCore\Tests\Setup\InMemorySetup;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing()]
class ProjectionAndReadModelIntegrationTest extends ProjectionAndReadModelIntegrationTestCase
{
    use InMemorySetup;
    private InMemoryCounterProjectionRepository $projectionRepo;

    protected function setupCounterProjections(): void
    {
        $this->projectionRepo = new InMemoryCounterProjectionRepository();
    }

    protected function getCounterProjectionRepository(): InMemoryCounterProjectionRepository
    {
        return $this->projectionRepo;
    }

    protected function getCounterFromProjection(
        string $aggregateId
    ): ?int {
        return $this->projectionRepo->getCounter($aggregateId);
    }

    protected function getProjectionCounter(
        string $aggregateId
    ): ?int {
        return $this->projectionRepo->getCounter($aggregateId);
    }

    protected function projectionRowExists(
        string $aggregateId
    ): bool {
        return $this->projectionRepo->rowExists($aggregateId);
    }

}
