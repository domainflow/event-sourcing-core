<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Crypto;

use SodiumException;

/**
 * `sodium_crypto_secretbox` — authenticated symmetric encryption from the
 * extension PHP bundles, so this costs no dependency.
 *
 * Authenticated matters here rather than being a nicety: the ciphertext sits
 * in a payload column that operators, backups and support tooling can all
 * reach, and an unauthenticated cipher would let an altered ciphertext decrypt
 * to something plausible instead of failing.
 *
 * A fresh nonce per value, prepended to the ciphertext. Reusing one across two
 * values encrypted with the same key — and a key here covers every event for a
 * subject — is the classic way to lose a stream cipher's guarantees entirely.
 */
final readonly class SodiumCipher implements CipherInterface
{
    public function encrypt(
        string $plaintext,
        string $key
    ): string {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    public function decrypt(
        string $ciphertext,
        string $key
    ): string {
        $raw = base64_decode($ciphertext, true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new CannotDecryptException('The stored ciphertext is truncated or not base64.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        try {
            $plaintext = sodium_crypto_secretbox_open($sealed, $nonce, $key);
        } catch (SodiumException $exception) {
            throw new CannotDecryptException('The stored ciphertext could not be opened.', 0, $exception);
        }

        if ($plaintext === false) {
            // Authentication failure: wrong key, or the ciphertext was
            // altered. Not the same as the key being gone, and not treated as
            // erasure — see CannotDecryptException.
            throw new CannotDecryptException('The stored ciphertext does not belong to this key.');
        }

        return $plaintext;
    }

    public function generateKey(): string
    {
        return sodium_crypto_secretbox_keygen();
    }
}
