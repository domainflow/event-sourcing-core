<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Outbox;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Interface\OutboxStorageInterface;
use DomainFlow\EventSourcing\Outbox\InMemoryOutboxStorage;
use DomainFlow\EventSourcing\Outbox\OutboxEntry;
use DomainFlow\EventSourcingCore\Provider\Unit\AbstractOutboxStorageTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(InMemoryOutboxStorage::class)]
#[CoversClass(OutboxEntry::class)]
#[UsesClass(EntityIdentifier::class)]
#[UsesClass(EventVersion::class)]
final class InMemoryOutboxStorageTest extends AbstractOutboxStorageTestCase
{
    protected function getOutbox(): OutboxStorageInterface
    {
        return new InMemoryOutboxStorage();
    }

    /**
     * One process, one store, and no lease: a claim this storage has handed
     * out is held until the entry is marked, whatever any clock says. So both
     * relays are the same object and the skew has nowhere to enter — which is
     * why this reference implementation cannot exhibit clock-skew lease races
     * that adapters could.
     *
     * @param int $leaseSeconds
     * @param int $skewSeconds
     * @return array{0: OutboxStorageInterface, 1: OutboxStorageInterface}
     */
    protected function getRelaysWithSkewedClocks(
        int $leaseSeconds,
        int $skewSeconds
    ): array {
        $shared = new InMemoryOutboxStorage();

        return [$shared, $shared];
    }
}
