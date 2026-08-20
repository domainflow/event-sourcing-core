<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Event;

use InvalidArgumentException;
use JsonSerializable;

final class EventVersion implements JsonSerializable
{
    private int $value;

    public function __construct(
        int $version
    ) {
        if ($version < 0) {
            throw new InvalidArgumentException('Event version must be greater or equal than 0');
        }

        $this->value = $version;
    }

    /**
     * Create a new EventVersion object with the initial version set to 1.
     *
     * @return self
     */
    public static function new(): self
    {
        return new self(1);
    }

    /**
     * The sentinel for "this event has not been given its place in the stream yet".
     *
     * A domain event is constructed before the aggregate knows where in its
     * history the event belongs, so it starts out unassigned and AggregateRoot
     * stamps the real version when the event is applied. Version 0 is never a
     * valid position in a stream (they are 1-based), which is what makes it
     * usable as the sentinel.
     *
     * @return self
     */
    public static function unassigned(): self
    {
        return new self(0);
    }

    /**
     * Whether this version denotes a real position in an event stream, as
     * opposed to the unassigned() sentinel.
     *
     * @return bool
     */
    public function isAssigned(): bool
    {
        return $this->value > 0;
    }
    /**
     * Create an EventVersion object from an integer.
     *
     * @param int $version
     * @return self
     */
    public static function fromInt(
        int $version
    ): self {
        return new self($version);
    }

    /**
     * Convert the EventVersion object to an integer.
     *
     * @return int
     */
    public function toInt(): int
    {
        return $this->value;
    }

    /**
     * Create a new EventVersion object with an incremented version.
     *
     * @return self
     */
    public function increment(): self
    {
        return $this->add(1);
    }

    /**
     * Create a new EventVersion object with an incremented version by the specified amount.
     *
     * @param int $amount
     * @return self
     */
    public function add(
        int $amount
    ): self {
        return new self($this->value + $amount);
    }

    /**
     * Compare two EventVersion objects for equality.
     *
     * @param EventVersion $other
     * @return bool
     */
    public function equals(
        EventVersion $other
    ): bool {
        return $this->value === $other->value;
    }

    /**
     * Convert the EventVersion object to a JSON-serializable format.
     *
     * @return int
     */
    public function jsonSerialize(): int
    {
        return $this->value;
    }
}
