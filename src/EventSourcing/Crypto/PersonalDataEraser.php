<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Crypto;

use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\SnapshotHistoryStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;

/**
 * Erasing a data subject, including the parts crypto-shredding cannot reach.
 *
 * **Destroying the key is not sufficient on its own, and that is the trap.** A
 * snapshot holds aggregate state that was derived from decrypted events, in
 * the clear — so a subject whose key is gone is still fully readable in every
 * snapshot of every aggregate that carried their data. The events go dark and
 * the summary of them does not.
 *
 * Encrypting snapshots too would work and is more machinery than it is worth:
 * a snapshot is a cache of a replay, so deleting it costs a slower load and
 * nothing else. Deleting is simpler and correct, so that is what this does.
 *
 * **Which aggregates carried a subject's data is the consumer's knowledge**,
 * not this package's — the mapping lives in their domain, and inventing an
 * index for it here would be a second source of truth about who is in what.
 */
final readonly class PersonalDataEraser
{
    public function __construct(
        private PersonalDataKeyStoreInterface $keys,
        private ?SnapshotStorageInterface $snapshots = null,
        private ?SnapshotHistoryStorageInterface $snapshotHistory = null
    ) {
    }

    /**
     * Forget the subject, and drop the snapshots of the aggregates that held
     * their data.
     *
     * Idempotent, because an erasure request that is retried must not fail —
     * and because the honest answer to "erase someone already erased" is that
     * it is done.
     *
     * @param string $subjectId
     * @param EntityIdentifierInterface ...$aggregateIds Aggregates known to
     *        have carried this subject's data.
     * @return void
     */
    public function erase(
        string $subjectId,
        EntityIdentifierInterface ...$aggregateIds
    ): void {
        // The key first. If snapshot deletion fails half way, the events are
        // already unreadable and a retry finishes the job; the other order
        // leaves the readable half standing.
        $this->keys->forget($subjectId);

        foreach ($aggregateIds as $aggregateId) {
            $this->snapshots?->deleteSnapshot($aggregateId);
            $this->snapshotHistory?->deleteAll($aggregateId);
        }
    }
}
