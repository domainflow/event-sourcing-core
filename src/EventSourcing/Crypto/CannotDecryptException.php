<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Crypto;

use RuntimeException;

/**
 * The key is there but does not open this ciphertext.
 *
 * Distinct from erasure on purpose. A missing key is an answer — the subject
 * asked to be forgotten — and produces `RedactedValue`. A key that is present
 * and does not work is a fault: the wrong key store is wired up, or the
 * ciphertext has been altered. Reporting the second as the first would turn a
 * misconfiguration into silent, permanent data loss.
 */
final class CannotDecryptException extends RuntimeException
{
}
