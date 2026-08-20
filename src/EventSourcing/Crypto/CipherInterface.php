<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Crypto;

/**
 * The algorithm behind crypto-shredding, kept behind an interface because a
 * consumer with a compliance requirement will have opinions about it — an
 * approved algorithm list, a hardware module, a KMS that never hands the key
 * out at all.
 */
interface CipherInterface
{
    /**
     * @param string $plaintext
     * @param string $key
     * @return string Ciphertext, safe to put in a JSON payload.
     */
    public function encrypt(string $plaintext, string $key): string;

    /**
     * @param string $ciphertext
     * @param string $key
     * @throws CannotDecryptException When the ciphertext does not belong to
     *         this key, or has been tampered with.
     * @return string
     */
    public function decrypt(string $ciphertext, string $key): string;

    /**
     * A fresh key for one data subject.
     *
     * @return string
     */
    public function generateKey(): string;
}
