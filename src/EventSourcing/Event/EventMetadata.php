<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Event;

/**
 * What the infrastructure knows about an event, as opposed to what the domain
 * put in it.
 *
 * Kept beside the payload rather than inside it, and the separation is the
 * point. The payload is the domain event's own data: it is versioned, it is
 * what upcasters transform, and it belongs to the aggregate. Metadata is
 * written by the framework, read by the framework, and must survive every
 * upcaster untouched — mixing the two would mean every upcaster had to
 * carefully preserve fields it does not own.
 *
 * ## Why these four
 *
 * - **Correlation** follows one business transaction across services. Without
 *   it a distributed trace stops at the process boundary.
 * - **Causation** records that this event happened because of that one. It is
 *   what makes a store auditable rather than merely durable.
 * - **Actor** is who did it — the first question asked of any audit log.
 * - **Tenant** is how a multi-tenant deployment partitions and, when it has
 *   to, erases.
 *
 * Everything else a deployment needs goes in `custom`, because a library
 * cannot anticipate it and inventing a fifth named field for each guess would
 * be worse than admitting that.
 *
 * All of it is optional. An event with no metadata is legal and costs nothing:
 * empty metadata serialises to an empty array, not to a shape full of nulls.
 */
final readonly class EventMetadata
{
    /**
     * @param array<string, mixed> $custom
     */
    private function __construct(
        private ?string $correlationId = null,
        private ?string $causationId = null,
        private ?string $actorId = null,
        private ?string $tenantId = null,
        private array $custom = []
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * Reads what was stored.
     *
     * Anything missing or of the wrong type is read as absent rather than as
     * an error: a row written before metadata existed has no field at all, and
     * failing to load history because of a field the history predates would be
     * the wrong trade entirely.
     *
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(
        array $data
    ): self {
        return new self(
            self::stringOrNull($data['correlationId'] ?? null),
            self::stringOrNull($data['causationId'] ?? null),
            self::stringOrNull($data['actorId'] ?? null),
            self::stringOrNull($data['tenantId'] ?? null),
            self::stringKeyed($data['custom'] ?? null)
        );
    }

    /**
     * Custom metadata as it was stored, minus anything that cannot have been
     * put there through this class.
     *
     * A numeric key is the shape JSON produces from a list, and the rest of
     * this class treats a field of the wrong type as absent rather than as an
     * error — a row predating the field must not be able to fail a load.
     *
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function stringKeyed(
        mixed $value
    ): array {
        if (!is_array($value)) {
            return [];
        }

        $custom = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $custom[$key] = $item;
            }
        }

        return $custom;
    }

    private static function stringOrNull(
        mixed $value
    ): ?string {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'correlationId' => $this->correlationId,
            'causationId' => $this->causationId,
            'actorId' => $this->actorId,
            'tenantId' => $this->tenantId,
            'custom' => $this->custom === [] ? null : $this->custom,
        ];

        return array_filter($data, static fn (mixed $value): bool => $value !== null);
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    public function getCausationId(): ?string
    {
        return $this->causationId;
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCustom(): array
    {
        return $this->custom;
    }

    public function withCorrelationId(
        ?string $correlationId
    ): self {
        return new self($correlationId, $this->causationId, $this->actorId, $this->tenantId, $this->custom);
    }

    public function withCausationId(
        ?string $causationId
    ): self {
        return new self($this->correlationId, $causationId, $this->actorId, $this->tenantId, $this->custom);
    }

    public function withActorId(
        ?string $actorId
    ): self {
        return new self($this->correlationId, $this->causationId, $actorId, $this->tenantId, $this->custom);
    }

    public function withTenantId(
        ?string $tenantId
    ): self {
        return new self($this->correlationId, $this->causationId, $this->actorId, $tenantId, $this->custom);
    }

    /**
     * @param array<string, mixed> $custom
     * @return self
     */
    public function withCustom(
        array $custom
    ): self {
        return new self($this->correlationId, $this->causationId, $this->actorId, $this->tenantId, $custom);
    }
}
