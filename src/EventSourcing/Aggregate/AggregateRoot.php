<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Aggregate;

use DateMalformedStringException;
use DomainFlow\EventSourcing\Event\EventStream;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use LogicException;
use ReflectionException;

abstract class AggregateRoot
{
    /** @var DomainEventInterface[] */
    protected array $uncommittedEvents = [];
    protected EventVersion $version;

    /**
     * Resolved apply-handler names, keyed by concrete aggregate class and then
     * by event class. The aggregate class must be part of the key: two
     * aggregate types routinely react to the same event class, and only one of
     * them may declare a handler for it.
     *
     * @var array<class-string<AggregateRoot>, array<class-string<DomainEventInterface>, string|null>>
     */
    private static array $eventHandlerCache = [];

    abstract public function __construct();

    /**
     * Create a new, empty instance of the concrete aggregate.
     * Implemented by concrete subclasses so the abstract base never
     * instantiates itself via `new static()`.
     *
     * @return static
     */
    abstract protected static function newInstance(): static;

    /**
     * Apply a domain event to the aggregate.
     *
     * @param DomainEventInterface $event
     * @param bool $isNew
     * @return void
     */
    public function applyEvent(
        DomainEventInterface $event,
        bool $isNew = true
    ): void {
        $handler = $this->resolveHandler($event::class);
        if ($handler !== null) {
            $this->{$handler}($event);
        }

        if (!isset($this->version)) {
            $this->version = EventVersion::unassigned();
        }

        if (!$isNew) {
            // Replaying history: the stored version is authoritative, and the
            // aggregate has to carry it forward so the next new event continues
            // the sequence instead of restarting at 1.
            $this->version = $event->getVersion();

            return;
        }

        $eventVersion = $event->getVersion();
        if (!$eventVersion->isAssigned()) {
            $eventVersion = $this->version->increment();
            $event->setVersion($eventVersion);
        }

        $this->version = $eventVersion;
        $this->uncommittedEvents[] = $event;
    }

    /**
     * Resolve the `apply<EventShortName>` handler for an event class on this
     * concrete aggregate, memoised per aggregate class.
     *
     * @param class-string<DomainEventInterface> $eventClass
     * @return string|null
     */
    private function resolveHandler(
        string $eventClass
    ): ?string {
        $aggregateClass = static::class;

        if (!array_key_exists($aggregateClass, self::$eventHandlerCache)) {
            self::$eventHandlerCache[$aggregateClass] = [];
        }

        if (!array_key_exists($eventClass, self::$eventHandlerCache[$aggregateClass])) {
            $position = strrpos($eventClass, '\\');
            $shortName = $position === false ? $eventClass : substr($eventClass, $position + 1);
            $handler = 'apply' . $shortName;

            self::$eventHandlerCache[$aggregateClass][$eventClass] = method_exists($this, $handler)
                ? $handler
                : null;
        }

        return self::$eventHandlerCache[$aggregateClass][$eventClass];
    }

    /**
     * The version of the last event applied to this aggregate, or the
     * unassigned sentinel for an aggregate with no history yet.
     *
     * @return EventVersion
     */
    public function getAggregateVersion(): EventVersion
    {
        return $this->version ?? EventVersion::unassigned();
    }

    /**
     * Seed the version when hydrating from a snapshot, before the events newer
     * than that snapshot are replayed on top.
     *
     * Infrastructure-facing: called by AggregateRepository, not by domain code.
     *
     * @param EventVersion $version
     * @return void
     */
    public function restoreVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }

    /**
     * Retrieve uncommitted events.
     *
     * @return DomainEventInterface[]
     */
    public function getUncommittedEvents(): array
    {
        return $this->uncommittedEvents;
    }

    /**
     * Clear uncommitted events after persisting.
     *
     * @return void
     */
    public function clearUncommittedEvents(): void
    {
        $this->uncommittedEvents = [];
    }

    /**
     * Reconstitutes an aggregate from an event stream.
     *
     * @param EventStream $stream
     * @throws ReflectionException|DateMalformedStringException|LogicException
     * @return static
     */
    public static function reconstitute(
        EventStream $stream
    ): static {
        if (static::class === self::class) {
            throw new LogicException(sprintf('%s is abstract and cannot be reconstituted directly.', self::class));
        }

        $instance = static::newInstance();

        foreach ($stream as $eventEntry) {
            $event = $eventEntry->toDomainEvent();
            $instance->applyEvent($event, false);
        }

        return $instance;
    }
}
