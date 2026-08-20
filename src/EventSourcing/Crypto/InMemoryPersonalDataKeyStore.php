<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Crypto;

/**
 * Reference implementation, and a test double — **not a production store.**
 *
 * Keys live for the length of the process, so every event written by one
 * request is unreadable by the next. That is the correct behaviour for a
 * reference implementation and useless for a real one; a durable store is the
 * consumer's, because its retention, backup and audit rules are theirs.
 */
final class InMemoryPersonalDataKeyStore implements PersonalDataKeyStoreInterface
{
    /** @var array<string, string> */
    private array $keys = [];

    public function __construct(
        private readonly CipherInterface $cipher = new SodiumCipher()
    ) {
    }

    public function ensureKeyFor(
        string $subjectId
    ): string {
        return $this->keys[$subjectId] ??= $this->cipher->generateKey();
    }

    public function keyFor(
        string $subjectId
    ): ?string {
        return $this->keys[$subjectId] ?? null;
    }

    public function forget(
        string $subjectId
    ): void {
        unset($this->keys[$subjectId]);
    }

    /**
     * Which subjects this store holds a key for — for tests that need to
     * assert a key was *not* minted.
     *
     * @return list<string>
     */
    public function knownSubjects(): array
    {
        return array_keys($this->keys);
    }
}
