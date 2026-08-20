<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Entity;

use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;

/**
 * A simple default implementation of EntityIdentifierInterface.
 */
class EntityIdentifier implements EntityIdentifierInterface
{
    protected string $value;

    public function __construct(
        string $value
    ) {
        $this->value = $value;
    }

    /**
     * Create an Entity Identifier from string data.
     *
     * @param string $value
     * @return self
     */
    public static function fromString(
        string $value
    ): self {
        return new self($value);
    }

    /**
     * Return the Entity Identifier as a string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Compare two Entity Identifiers for equality.
     *
     * @param EntityIdentifierInterface $other
     * @return bool
     */
    public function equals(
        EntityIdentifierInterface $other
    ): bool {
        if (!($other instanceof static)) {
            return false;
        }

        return $this->value === (string) $other;
    }
}
