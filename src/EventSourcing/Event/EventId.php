<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Event;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\Uuid\UuidV6;
use Random\RandomException;

final class EventId extends EntityIdentifier
{
    public function __construct(
        UuidV6 $value
    ) {
        parent::__construct((string) $value);
    }

    /**
     * Create an EventId from string.
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
     * Generate a new EventId.
     *
     * @throws RandomException
     * @return self
     */
    public static function generate(): self
    {
        return new self(UuidV6::generate());
    }

    /**
     * Convert the EventId back to a UuidV6 object
     *
     * @return UuidV6
     */
    public function toUuid(): UuidV6
    {
        return UuidV6::fromString((string) $this);
    }
}
