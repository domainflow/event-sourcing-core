<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Projector;

use DomainFlow\EventSourcing\Interface\EventDispatcherInterface;
use DomainFlow\EventSourcing\Interface\ProjectorInterface;
use RuntimeException;

/**
 * Central registry for managing and registering projectors with an event dispatcher.
 */
final class ProjectorRegistry
{
    /** @var ProjectorInterface[] */
    private array $projectors = [];

    /**
     * Add a projector to the registry.
     *
     * @param ProjectorInterface $projector
     * @return void
     */
    public function add(
        ProjectorInterface $projector
    ): void {
        $this->projectors[] = $projector;
    }

    /**
     * Clears all registered projectors.
     *
     * Useful for test resets.
     */
    public function clear(): void
    {
        $this->projectors = [];
    }

    /**
     * Registers all added projectors with the given dispatcher.
     *
     * @param EventDispatcherInterface $dispatcher
     * @throws RuntimeException
     * @return void
     */
    public function registerAll(
        EventDispatcherInterface $dispatcher
    ): void {
        foreach ($this->projectors as $projector) {
            if (!$projector instanceof ProjectorInterface) {
                throw new RuntimeException('Invalid projector instance in registry.');
            }

            $dispatcher->register($projector);
        }
    }
}
