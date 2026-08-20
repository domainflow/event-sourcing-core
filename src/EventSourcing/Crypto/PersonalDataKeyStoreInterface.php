<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Crypto;

/**
 * One key per data subject, and erasure by destroying it.
 *
 * The store is the only part of crypto-shredding that has to be durable and
 * backed up carefully — and the only part that must be *deletable*, which is
 * the opposite of what the event store is for. Keeping them apart is the whole
 * design: the events stay append-only and untouched, and the thing that can be
 * deleted lives somewhere that can delete.
 *
 * Consequences worth stating, because they are the ones that bite:
 *
 * - **A backup of the key store undoes an erasure.** Whatever restores it must
 *   know not to bring back keys that were forgotten on purpose.
 * - **Losing the store erases everyone.** It is not a cache.
 *
 * Core ships an in-memory reference implementation. A real one — a table, a
 * KMS, a secrets manager — belongs to the consumer, whose retention and audit
 * rules it has to satisfy.
 */
interface PersonalDataKeyStoreInterface
{
    /**
     * The subject's key, minting one if this is the first time.
     *
     * The write path. A subject keeps one key for the life of their data:
     * minting a second would leave the first event readable after an erasure
     * that was supposed to cover both.
     *
     * @param string $subjectId
     * @return string
     */
    public function ensureKeyFor(string $subjectId): string;

    /**
     * The subject's key, or null if there is none.
     *
     * The read path, and null is an answer rather than an error: it is what
     * "this subject asked to be forgotten" looks like from here.
     *
     * @param string $subjectId
     * @return string|null
     */
    public function keyFor(string $subjectId): ?string;

    /**
     * Destroy the subject's key. Idempotent.
     *
     * This is the erasure. Every event carrying that subject's data becomes
     * unreadable at once, without a single event being rewritten.
     *
     * @param string $subjectId
     * @return void
     */
    public function forget(string $subjectId): void;
}
