<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Snapshot;

use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\SnapshotFactoryInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use InvalidArgumentException;
use ReflectionClass;
use Throwable;

/**
 * The default snapshot factory, so snapshotting works without the consumer
 * writing one.
 *
 * Builds a GenericSnapshot for the common case, and for a custom snapshot
 * class whose constructor matches GenericSnapshot's shape
 * (aggregateId, version, state, occurredOn) builds that instead. A class with
 * a different constructor needs its own factory — which is what
 * SnapshotFactoryInterface is for.
 */
final class GenericSnapshotFactory implements SnapshotFactoryInterface
{
    public function createFromStorage(
        string $snapshotClass,
        EntityIdentifierInterface $aggregateId,
        EventVersion $version,
        array $state
    ): SnapshotInterface {
        if ($snapshotClass === GenericSnapshot::class) {
            return new GenericSnapshot($aggregateId, $version, $state, OccurredOn::now());
        }

        if (!is_a($snapshotClass, SnapshotInterface::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Snapshot class "%s" must implement SnapshotInterface.',
                $snapshotClass
            ));
        }

        try {
            $snapshot = (new ReflectionClass($snapshotClass))
                ->newInstance($aggregateId, $version, $state, OccurredOn::now());
        } catch (Throwable) {
            throw new InvalidArgumentException(sprintf(
                'Snapshot class "%s" cannot be built by %s: register a SnapshotFactoryInterface for it.',
                $snapshotClass,
                self::class
            ));
        }

        // is_a() above already established the class implements the interface,
        // so the constructed instance needs no second check.
        return $snapshot;
    }
}
