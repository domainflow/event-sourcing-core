<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Crypto;

use DomainFlow\EventSourcing\Crypto\CannotDecryptException;
use DomainFlow\EventSourcing\Crypto\SodiumCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SodiumCipher::class)]
#[CoversClass(CannotDecryptException::class)]
final class SodiumCipherTest extends TestCase
{
    public function test_it_round_trips(): void
    {
        $cipher = new SodiumCipher();
        $key = $cipher->generateKey();

        $this->assertSame('ada@example.com', $cipher->decrypt($cipher->encrypt('ada@example.com', $key), $key));
    }

    /**
     * A key covers every event for a subject, so a reused nonce would be two
     * values encrypted under one keystream — the classic way to lose the
     * guarantee entirely. Two encryptions of the same value must not match.
     */
    public function test_the_same_value_encrypts_differently_every_time(): void
    {
        $cipher = new SodiumCipher();
        $key = $cipher->generateKey();

        $this->assertNotSame($cipher->encrypt('ada@example.com', $key), $cipher->encrypt('ada@example.com', $key));
    }

    /**
     * Authentication is why this cipher and not a bare stream: the ciphertext
     * sits in a column that operators, backups and support tooling can all
     * reach, and an altered one must fail rather than decrypt to something
     * plausible.
     */
    public function test_a_tampered_ciphertext_is_refused(): void
    {
        $cipher = new SodiumCipher();
        $key = $cipher->generateKey();

        $raw = base64_decode($cipher->encrypt('ada@example.com', $key), true);
        $this->assertNotFalse($raw);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'a' ? 'b' : 'a';

        $this->expectException(CannotDecryptException::class);
        $this->expectExceptionMessage('does not belong to this key');

        $cipher->decrypt(base64_encode($raw), $key);
    }

    /**
     * A key that is present and does not work is a fault — the wrong key store
     * is wired up — and must not be reported as erasure, which would turn a
     * misconfiguration into silent, permanent data loss.
     */
    public function test_the_wrong_key_is_a_fault_rather_than_an_erasure(): void
    {
        $cipher = new SodiumCipher();

        $this->expectException(CannotDecryptException::class);

        $cipher->decrypt($cipher->encrypt('ada@example.com', $cipher->generateKey()), $cipher->generateKey());
    }

    public function test_a_value_that_is_not_base64_is_refused(): void
    {
        $this->expectException(CannotDecryptException::class);
        $this->expectExceptionMessage('truncated or not base64');

        (new SodiumCipher())->decrypt('!!!not base64!!!', (new SodiumCipher())->generateKey());
    }

    /**
     * Shorter than a nonce cannot be anything this cipher wrote, and must not
     * reach sodium as a substring operation on nothing.
     */
    public function test_a_value_too_short_to_hold_a_nonce_is_refused(): void
    {
        $this->expectException(CannotDecryptException::class);
        $this->expectExceptionMessage('truncated or not base64');

        (new SodiumCipher())->decrypt(base64_encode('short'), (new SodiumCipher())->generateKey());
    }

    /**
     * Sodium throws rather than returning false when the key length is wrong,
     * and that has to arrive as this package's exception like every other
     * failure to open a value.
     */
    public function test_a_key_of_the_wrong_length_is_reported_as_a_decryption_failure(): void
    {
        $cipher = new SodiumCipher();

        $this->expectException(CannotDecryptException::class);
        $this->expectExceptionMessage('could not be opened');

        $cipher->decrypt($cipher->encrypt('ada@example.com', $cipher->generateKey()), 'too-short');
    }
}
