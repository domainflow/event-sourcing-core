<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Crypto;

use DomainFlow\EventSourcing\Crypto\InMemoryPersonalDataKeyStore;
use DomainFlow\EventSourcing\Crypto\PersonalDataEraser;
use DomainFlow\EventSourcing\Crypto\SodiumCipher;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersonalDataEraser::class)]
#[UsesClass(InMemoryPersonalDataKeyStore::class)]
#[UsesClass(SodiumCipher::class)]
#[UsesClass(EntityIdentifier::class)]
final class PersonalDataEraserTest extends TestCase
{
    /**
     * The trap this class exists for. A snapshot holds state derived from
     * decrypted events, in the clear — so destroying the key leaves the
     * subject fully readable in every snapshot of every aggregate that carried
     * their data. The events go dark and the summary of them does not.
     */
    public function test_it_forgets_the_key_and_drops_the_snapshots_that_still_hold_the_data(): void
    {
        $keys = new InMemoryPersonalDataKeyStore();
        $keys->ensureKeyFor('customer-1');

        $aggregateId = EntityIdentifier::fromString('order-1');

        $snapshots = $this->createMock(SnapshotStorageInterface::class);
        $snapshots->expects($this->once())->method('deleteSnapshot')->with($aggregateId);

        $history = $this->createMock(SnapshotHistoryStorageInterface::class);
        $history->expects($this->once())->method('deleteAll')->with($aggregateId);

        (new PersonalDataEraser($keys, $snapshots, $history))->erase('customer-1', $aggregateId);

        $this->assertNull($keys->keyFor('customer-1'));
    }

    /**
     * A consumer who keeps no snapshots should not have to supply two nulls to
     * say so, and erasing must still work.
     */
    public function test_it_works_without_any_snapshot_storage(): void
    {
        $keys = new InMemoryPersonalDataKeyStore();
        $keys->ensureKeyFor('customer-2');

        (new PersonalDataEraser($keys))->erase('customer-2', EntityIdentifier::fromString('order-2'));

        $this->assertNull($keys->keyFor('customer-2'));
    }

    /**
     * An erasure request that is retried must not fail — and the honest answer
     * to erasing someone already erased is that it is done.
     */
    public function test_erasing_twice_is_not_an_error(): void
    {
        $keys = new InMemoryPersonalDataKeyStore();
        $keys->ensureKeyFor('customer-3');

        $eraser = new PersonalDataEraser($keys);
        $eraser->erase('customer-3');
        $eraser->erase('customer-3');

        $this->assertNull($keys->keyFor('customer-3'));
    }
}
