<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Integration;

/**
 * Reads typed values out of an event payload.
 *
 * A payload is `array<string, mixed>` by definition — it came back from a
 * store — so every fixture rebuilding an event from one has the same handful
 * of narrowing steps to do. Written once here rather than inline in each
 * fixture, where the casts were simply left out and static analysis had no
 * way to see the assumption.
 */
trait ReadsPayloadValues
{
    /**
     * @param array<string, mixed> $payload
     */
    private static function payloadString(
        array $payload,
        string $key,
        string $default = ''
    ): string {
        $value = $payload[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function payloadInt(
        array $payload,
        string $key,
        int $default = 0
    ): int {
        $value = $payload[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function payloadBool(
        array $payload,
        string $key,
        bool $default = false
    ): bool {
        $value = $payload[$key] ?? null;

        return is_scalar($value) ? (bool) $value : $default;
    }
}
