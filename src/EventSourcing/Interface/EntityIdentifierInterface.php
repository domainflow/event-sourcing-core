<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

/**
 * An optional interface for typed entity/aggregate IDs.
 */
interface EntityIdentifierInterface
{
    /**
     * Create an identifier from string data.
     *
     * @param string $value
     * @return self
     */
    public static function fromString(string $value): self;

    /**
     * Return the ID as a string.
     *
     * @return string
     */
    public function __toString(): string;

    /**
     * Compare two identifiers for equality.
     *
     * @param EntityIdentifierInterface $other
     * @return bool
     */
    public function equals(EntityIdentifierInterface $other): bool;
}
