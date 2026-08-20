<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Crypto;

/**
 * What is left where personal data was, once the key is gone.
 *
 * **A marker rather than null, and that is the decision the whole feature
 * turns on.** Returning null would make a replay produce an aggregate whose
 * state never existed, and a projector
 * could not tell "never set" from "erased". A value that announces itself lets
 * a consumer branch on it: show a placeholder, skip the projection, or refuse
 * to act, whichever the domain calls for.
 *
 * The marker is a string because it has to fit where the value was. That is
 * also why `#[PersonalData]` only accepts string-typed properties.
 */
final readonly class RedactedValue
{
    /**
     * Deliberately not something a person would type. A domain that genuinely
     * stores this string is indistinguishable from an erased one, and the odds
     * of that are what this shape is chosen for.
     */
    public const string MARKER = "\u{2205}redacted:personal-data\u{2205}";

    public static function isRedacted(
        mixed $value
    ): bool {
        return $value === self::MARKER;
    }
}
