<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

/**
 * Interface for migrating payloads between event versions.
 */
interface EventMigrationInterface
{
    /**
     * Whether this migration supports the given event and version.
     *
     * @param string $eventClass
     * @param int $version
     * @return bool
     */
    public function supports(string $eventClass, int $version): bool;

    /**
     * Migrate payload from one version to another.
     *
     * @param array<string, mixed> $payload
     * @param int $fromVersion
     * @param int $toVersion
     * @return array<string, mixed>
     */
    public function migrate(array $payload, int $fromVersion, int $toVersion): array;
}
