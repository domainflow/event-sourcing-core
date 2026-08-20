<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Aggregate;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\Uuid\UuidV6;
use Random\RandomException;

/**
 *  Value object AggregateId.
 *
 * This class is used to represent the unique identifier of an aggregate.
 */
final class AggregateId extends EntityIdentifier
{
    /**
     * Enforce UuidV6.
     *
     * @param UuidV6 $value
     */
    public function __construct(
        UuidV6 $value
    ) {
        parent::__construct((string) $value);
    }

    /**
     * Create an AggregateId from string data.
     *
     * @param string $value
     * @return self
     */
    public static function fromString(
        string $value
    ): self {
        return new self(UuidV6::fromString($value));
    }

    /**
     * Generate a new AggregateId.
     *
     * @throws RandomException
     * @return self
     */
    public static function generate(): self
    {
        return new self(UuidV6::generate());
    }

    /**
     * Convert the AggregateId back to a UuidV6 object
     *
     * @return UuidV6
     */
    public function toUuid(): UuidV6
    {
        return UuidV6::fromString((string) $this);
    }
}
