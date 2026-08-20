<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Event;

/**
 * Represents a fully-formed event record ready for persistence.
 */
final readonly class EventPersistenceRecord
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        private array $fields
    ) {
    }

    /**
     * Get the SQL column names.
     *
     * @return string[]
     */
    public function getPersistenceColumns(): array
    {
        return array_keys($this->fields);
    }

    /**
     * Get the SQL placeholders.
     *
     * @return string[]
     */
    public function getPersistencePlaceholders(): array
    {
        return array_map(
            fn ($field) => ":$field",
            array_keys($this->fields)
        );
    }

    /**
     * Get the SQL values.
     *
     * @return array<string, mixed>
     */
    public function getValues(): array
    {
        return $this->fields;
    }

    /**
     * Create a record from an associative array.
     *
     * @param array<string, mixed> $fields
     */
    public static function fromArray(
        array $fields
    ): self {
        return new self($fields);
    }

    /**
     * Access raw field array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->fields;
    }
}
