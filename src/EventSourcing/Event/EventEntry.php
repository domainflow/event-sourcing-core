<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Event;

use BackedEnum;
use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventFactoryInterface;
use DomainFlow\EventSourcing\Interface\EventUpcasterInterface;
use DomainFlow\Uuid\UuidV6;
use Random\RandomException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/**
 * A simple DTO for storing event data in a database.
 */
class EventEntry
{
    public const string DATE_FORMAT = 'Y-m-d H:i:s.u';

    /**
     * The payload key a stored event records its schema version under.
     *
     * In the payload because that is where a schema version belongs — it
     * describes the shape of exactly that data — and because no adapter has a
     * column for it, so putting it anywhere else would mean a migration in
     * every store before any of this could work.
     *
     * Underscored so it cannot collide with a constructor parameter name, and
     * stripped again before the payload reaches an upcaster, a factory or
     * reflection: it is this package's bookkeeping, not the domain's data.
     */
    public const string SCHEMA_VERSION_KEY = '_schemaVersion';

    /**
     * The factory and upcaster used to be process-wide statics, written by
     * every storage constructor. Two stores in one service — two
     * bounded contexts, or MySQL for events beside Redis for something else —
     * meant the second constructor silently wiped the first store's factory,
     * and the symptom was a reconstruction failure or a quietly wrong event
     * depending on which order the container happened to build them in.
     *
     * They are per-entry now, so the entry carries the rules for rebuilding
     * itself and nothing global decides on its behalf.
     */
    public function __construct(
        public string $eventClass,
        public EntityIdentifierInterface $aggregateId,
        public EventId $eventId,
        public OccurredOn $occurredOn,
        public EventVersion $version,
        /** @var array<string, mixed> */
        public array $payload = [],
        private readonly ?EventFactoryInterface $factory = null,
        private readonly ?EventUpcasterInterface $upcaster = null,
        /**
         * What the infrastructure knows about this event. Held beside
         * the payload rather than in it, so an upcaster transforming the
         * domain's data cannot lose or have to preserve it.
         */
        private readonly ?EventMetadata $metadata = null,
        /**
         * Which **shape** the payload was written in, as opposed to `$version`,
         * which is the event's **place in its aggregate's stream**.
         *
         * Null unless an adapter knows better than the payload does — a store
         * that keeps the schema version in a column of its own says so here.
         * Otherwise it is read from the payload, and a payload that does not
         * carry it is the first schema, which is what every row written before
         * the marker existed is.
         */
        public readonly ?int $schemaVersion = null
    ) {
    }

    /**
     * The event this entry already holds, when it was built from one that had
     * been reconstructed elsewhere. Null for an entry built from stored data,
     * which is the case that has to rebuild.
     *
     * Not a constructor argument: it is not part of what an entry *stores*,
     * and nothing outside this class puts an event here.
     */
    private ?DomainEventInterface $reconstructed = null;

    /**
     * The same entry, rebuilt against a different factory and upcaster.
     *
     * A storage adapter builds entries from stored rows and knows which
     * collaborators belong to it, so it stamps them here rather than into a
     * static.
     *
     * @param EventFactoryInterface|null $factory
     * @param EventUpcasterInterface|null $upcaster
     * @return self
     */
    public function withReconstructionRules(
        ?EventFactoryInterface $factory,
        ?EventUpcasterInterface $upcaster = null
    ): self {
        $entry = new self(
            $this->eventClass,
            $this->aggregateId,
            $this->eventId,
            $this->occurredOn,
            $this->version,
            $this->payload,
            $factory,
            $upcaster,
            $this->metadata,
            $this->schemaVersion
        );

        // Carried over rather than dropped. An entry that already holds its
        // event holds the *result* of rules that have run; stamping a new set
        // on it does not make that result stale, and silently rebuilding from
        // the payload instead would undo them.
        $entry->reconstructed = $this->reconstructed;

        return $entry;
    }

    /**
     * Create an EventEntry from a DomainEvent.
     *
     * @param DomainEventInterface $event
     * @throws DateMalformedStringException|RandomException
     * @return EventEntry
     */
    public static function fromDomainEvent(
        DomainEventInterface $event
    ): self {
        $arr = $event->toArray();

        return new self(
            eventClass: $event::class,
            aggregateId: $event->getAggregateId(),
            eventId: isset($arr['eventId']) && is_string($arr['eventId']) ? new EventId(UuidV6::fromString($arr['eventId'])) : new EventId(UuidV6::generate()),
            occurredOn: self::asOccurredOn($event->getOccurredOn()),
            version: isset($arr['version']) && is_numeric($arr['version'])
                    ? new EventVersion((int) $arr['version'])
                    : new EventVersion(1),
            payload: $arr,
            metadata: $event->getMetadata(),
            // An event held in memory was just built by its own class, so it
            // is at whatever schema that class currently declares.
            schemaVersion: self::declaredSchemaVersion($event::class),
        );
    }

    /**
     * The latest payload schema an event class declares, or null for a class
     * that does not version its payload at all.
     *
     * Public because both ends of the round trip need the same answer: the
     * entry factory stamps it onto a payload it is writing, and this class
     * compares a stored payload against it when reading one back.
     *
     * @param string $eventClass
     * @throws RuntimeException
     * @return int|null
     */
    public static function declaredSchemaVersion(
        string $eventClass
    ): ?int {
        if (!method_exists($eventClass, 'getLatestSchemaVersion')) {
            return null;
        }

        $declared = $eventClass::getLatestSchemaVersion();

        // Both of these are duck-typed hooks — there is no interface to hold an
        // event to, so the return type is whatever the event happens to give
        // back. Casting it quietly was the worse half of that bargain: a
        // non-numeric return became 0, every stored version compared greater,
        // and the payload went through unmigrated with nothing reported.
        if (!is_int($declared)) {
            throw new RuntimeException(sprintf(
                '%s::getLatestSchemaVersion() must return an int, %s given.',
                $eventClass,
                get_debug_type($declared)
            ));
        }

        return $declared;
    }

    /**
     * The instant an event carries, as this package stores instants: UTC.
     *
     * Read from the event's own timestamp rather than from the string in its
     * payload. The stored format carries no offset, so parsing that string
     * without stating a zone read it as local time and moved it — an event
     * that happened at 10:00 UTC became an entry at 08:00 UTC under
     * `Europe/Berlin` in summer, a value nobody wrote, drifting again on every
     * read-and-store cycle.  settled this everywhere else in this class;
     * this was the path it did not reach.
     *
     * @param DateTimeImmutable $occurredOn
     * @throws DateMalformedStringException
     * @return OccurredOn
     */
    private static function asOccurredOn(
        DateTimeImmutable $occurredOn
    ): OccurredOn {
        // Already normalised by its own constructor.
        return $occurredOn instanceof OccurredOn
            ? $occurredOn
            : OccurredOn::fromString(
                $occurredOn->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT)
            );
    }

    /**
     * An entry around an event that has already been reconstructed.
     *
     * A storage adapter hands back domain events, not rows: by the time one
     * reaches this class the upcaster, the factory and the payload migration
     * have all run. Building an entry with `fromDomainEvent()` and letting it
     * rebuild would serialise that event and hydrate the copy — and the copy
     * is not the same object. Reflection stands in for a factory the entry no
     * longer has, a migration runs a second time against a payload that has
     * already been through it, and anything `toArray()` does not expose is
     * gone.
     *
     * The entry still describes the event — class, id, version, timestamp,
     * payload — because callers read those. What changes is that
     * `toDomainEvent()` gives back the event it was given.
     *
     * @param DomainEventInterface $event
     * @throws DateMalformedStringException|RandomException
     * @return self
     */
    public static function fromReconstructedEvent(
        DomainEventInterface $event
    ): self {
        $entry = self::fromDomainEvent($event);
        $entry->reconstructed = $event;

        return $entry;
    }

    /**
     * Reconstruct a DomainEvent from an EventEntry.
     *
     * @throws ReflectionException|DateMalformedStringException
     * @return DomainEventInterface
     */
    public function toDomainEvent(): DomainEventInterface
    {
        // Metadata is not re-applied here: an event that was reconstructed
        // elsewhere is already carrying its own.
        return $this->reconstructed ?? $this->withStoredMetadata($this->rebuild());
    }

    /**
     * Puts the stored metadata back on a rebuilt event.
     *
     * Applied here rather than in each rebuild path, and after all of them,
     * because metadata is not the domain's data: no constructor takes it, no
     * factory knows about it, and an upcaster must not have to carry it
     * through.
     *
     * @param DomainEventInterface $event
     * @return DomainEventInterface
     */
    private function withStoredMetadata(
        DomainEventInterface $event
    ): DomainEventInterface {
        // Empty metadata is left alone rather than attached as an empty
        // object: "nobody set any" and "someone set nothing" are the same
        // fact, and a rebuilt event should be indistinguishable from the one
        // that was stored.
        return $this->metadata === null || $this->metadata->isEmpty()
            ? $event
            : $event->withMetadata($this->metadata);
    }

    /**
     * @throws ReflectionException|DateMalformedStringException
     * @return DomainEventInterface
     */
    private function rebuild(): DomainEventInterface
    {
        $payload = $this->payload;
        $eventClass = $this->eventClass;

        $schemaVersion = $this->payloadSchemaVersion();
        unset($payload[self::SCHEMA_VERSION_KEY]);

        if ($this->upcaster !== null && $this->upcaster->supports($eventClass)) {
            return $this->upcaster->upcast($eventClass, $payload);
        }

        $payload = $this->migratePayload($payload, $schemaVersion);

        if ($this->factory !== null) {
            return $this->rebuildViaFactory($payload);
        }

        if (!class_exists($this->eventClass)) {
            throw new RuntimeException("Event class {$this->eventClass} not found.");
        }

        if ($this->hasClassFactory()) {
            return $this->rebuildViaClassFactory($payload);
        }

        return $this->rebuildViaReflection($payload);
    }

    /**
     * Reconstruct a DomainEvent from an EventEntry using the factory.
     *
     * @param array<string, mixed> $payload
     * @return DomainEventInterface
     */
    private function rebuildViaFactory(
        array $payload
    ): DomainEventInterface {
        if ($this->factory === null) {
            throw new RuntimeException('Event factory is not set.');
        }

        return $this->factory->createFromPayload($this->eventClass, $payload);
    }

    /**
     * Check if the event class has a factory method.
     *
     * @return bool
     */
    private function hasClassFactory(): bool
    {
        return method_exists($this->eventClass, 'getFactory');
    }

    /**
     * Reconstruct a DomainEvent from an EventEntry using the class factory.
     *
     * @param array<string, mixed> $payload
     * @throws ReflectionException|DateMalformedStringException
     */
    private function rebuildViaClassFactory(
        array $payload
    ): DomainEventInterface {
        /** @var class-string $eventClass */
        $eventClass = $this->eventClass;
        $factory = $eventClass::getFactory();

        if ($factory === null) {
            return $this->rebuildViaReflection($payload);
        }

        if (is_object($factory) && method_exists($factory, 'createFromPayload')) {
            $event = $factory->createFromPayload($eventClass, $payload);
        } elseif (is_callable($factory)) {
            $event = $factory($payload);
        } else {
            throw new RuntimeException("Invalid factory returned from $eventClass::getFactory()");
        }

        if (!$event instanceof DomainEventInterface) {
            throw new RuntimeException("Reconstructed event is not an instance of DomainEventInterface.");
        }

        return $event;
    }

    /**
     * Reconstruct a DomainEvent from an EventEntry using reflection.
     *
     * @param array<string, mixed> $payload
     * @throws ReflectionException|DateMalformedStringException
     */
    private function rebuildViaReflection(
        array $payload
    ): DomainEventInterface {
        /** @var class-string $eventClass */
        $eventClass = $this->eventClass;

        $reflection = new ReflectionClass($eventClass);
        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            throw new RuntimeException("Event class {$this->eventClass} has no constructor.");
        }

        $args = $this->resolveConstructorArguments($constructor, $payload);
        $event = $reflection->newInstanceArgs($args);

        if (!$event instanceof DomainEventInterface) {
            throw new RuntimeException("Reconstructed event is not an instance of DomainEventInterface.");
        }

        return $event;
    }

    /**
     * Resolve the constructor arguments for the event class.
     *
     * Payload keys are matched against constructor **parameter names**. That
     * makes a parameter name part of the stored data format: renaming one is a
     * data migration, not a refactor. The factory escape hatches are available
     * when reflection is
     * the wrong tool — `getFactory()` on the event class, or an injected
     * EventFactoryInterface.
     *
     * @param ReflectionMethod $constructor
     * @param array<string, mixed> $payload
     * @throws RuntimeException If a required payload field is missing, or the
     *         payload and the constructor disagree about a name
     * @throws DateMalformedStringException|ReflectionException
     * @return array<int, mixed>
     */
    private function resolveConstructorArguments(
        ReflectionMethod $constructor,
        array $payload
    ): array {
        $args = [];
        $consumed = [];
        $unfilledOptionals = [];

        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            $isFilled = array_key_exists($name, $payload);

            if (!$isFilled && !$param->isOptional()) {
                throw new RuntimeException(
                    sprintf(
                        'Missing required payload field "%s" for event class %s',
                        $name,
                        $this->eventClass
                    )
                );
            }

            if ($isFilled) {
                $consumed[] = $name;
            } else {
                $unfilledOptionals[] = $name;
            }

            // array_key_exists, not ??: a payload that explicitly stores null
            // means null, and ?? would silently swap in the parameter default
            // instead — turning a stored value into a fabricated one.
            $value = $isFilled ? $payload[$name] : $param->getDefaultValue();

            $type = $param->getType();
            if ($type instanceof ReflectionNamedType) {
                $typeName = $type->getName();

                if ($type->isBuiltin()) {
                    $args[] = $this->validateAndConvertScalar($name, $typeName, $value, $type->allowsNull());
                    continue;
                }

                $args[] = $this->resolveSpecialType($typeName, $value, $name);
                continue;
            }

            $args[] = $value;
        }

        $this->assertPayloadAndConstructorAgree($payload, $consumed, $unfilledOptionals);

        return $args;
    }

    /**
     * Refuses a payload and a constructor that disagree about a name.
     *
     * An optional parameter no key filled is normally fine — it is exactly
     * what defaults and upcasting are for, and an event written before a field
     * existed must keep loading. What is *not* fine is that happening while
     * the payload also carries keys no parameter consumed: the two together
     * mean the same value is present under one name and expected under
     * another.
     *
     * That combination used to be silent. An event whose constructor parameter
     * is named `$o` rather than `$occurredOn` was reconstructed with a **fresh
     * timestamp on every load** — differing from the stored one by however
     * long the row had been sitting there — and the aggregate replayed
     * happily. Found during the  audit, which is the only way it could
     * have been found.
     *
     * @param array<string, mixed> $payload
     * @param list<string> $consumed
     * @param list<string> $unfilledOptionals
     * @throws RuntimeException
     */
    private function assertPayloadAndConstructorAgree(
        array $payload,
        array $consumed,
        array $unfilledOptionals
    ): void {
        if ($unfilledOptionals === []) {
            return;
        }

        $unused = array_values(array_diff(array_keys($payload), $consumed));

        if ($unused === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Cannot reconstruct %s: the stored payload carries %s that no constructor parameter takes, '
            . 'while %s went unfilled. Reflection matches payload keys against parameter names, '
            . 'so a parameter name is part of your stored data format — renaming one is a migration. '
            . 'If the payload really has no value for it, remove the surplus key or give the event a '
            . 'factory (%s::getFactory(), or an injected EventFactoryInterface).',
            $this->eventClass,
            $this->nameList($unused, 'key', 'keys'),
            $this->nameList(array_map(static fn (string $name): string => '$' . $name, $unfilledOptionals), 'parameter', 'parameters'),
            $this->eventClass
        ));
    }

    /**
     * "key \'a\'" or "keys \'a\', \'b\'" — the plural is worth the four lines,
     * because these messages are read by someone whose event will not load and
     * who has to find one name in a list.
     *
     * @param list<string> $names
     */
    private function nameList(
        array $names,
        string $singular,
        string $plural
    ): string {
        return sprintf(
            '%s %s',
            count($names) === 1 ? $singular : $plural,
            implode(', ', array_map(static fn (string $name): string => "'" . $name . "'", $names))
        );
    }

    /**
     * Validates and casts scalar payload values (string, int, float, bool).
     *
     * Null passes straight through when the parameter is nullable.
     * It used to be rejected — `!is_scalar(null)` is true — so an event with
     * any optional scalar in its constructor threw `Expected string for 'x',
     * got NULL` on **every** load. `?string $eventId = null` is the shape half
     * the fixtures in this tree use; the only reason it had not reached a
     * consumer is that nothing in the suite had a nullable scalar until
     * needed one and worked around it.
     *
     * A *non*-nullable parameter still rejects null, deliberately: turning
     * that into a TypeError at construction would move the failure one frame
     * away from the thing that is actually wrong.
     *
     * @param string $paramName
     * @param string $expectedType
     * @param mixed $value
     * @param bool $allowsNull Whether the declared parameter type is nullable.
     * @throws RuntimeException
     * @return mixed
     */
    private function validateAndConvertScalar(
        string $paramName,
        string $expectedType,
        mixed $value,
        bool $allowsNull = false
    ): mixed {
        if ($value === null && $allowsNull) {
            return null;
        }

        switch ($expectedType) {
            case 'string':
                if (!is_scalar($value)) {
                    throw new RuntimeException("Expected string for '$paramName', got " . gettype($value));
                }

                return (string) $value;

            case 'int':
                if (!is_numeric($value)) {
                    throw new RuntimeException("Expected int for '$paramName', got " . gettype($value));
                }

                return (int) $value;

            case 'float':
                if (!is_numeric($value)) {
                    throw new RuntimeException("Expected float for '$paramName', got " . gettype($value));
                }

                return (float) $value;

            case 'bool':
                if (!is_bool($value) && !in_array($value, [0, 1, '0', '1'], true)) {
                    throw new RuntimeException("Expected bool for '$paramName', got " . gettype($value));
                }

                return (bool) $value;

            default:
                return $value;
        }
    }

    /**
     * Converts a payload value into the class the constructor asks for.
     *
     * The set of understood types is deliberately small. Anything not in it is
     * handed over untouched, which
     * is the right answer for a value object the payload already stores in its
     * final form and the wrong one for everything else — that is what the
     * factory escape hatches are for.
     *
     * @param string $typeName
     * @param mixed $value
     * @param string $paramName Named only so a failure can say which parameter
     *        it was resolving; resolution itself does not depend on it.
     * @throws DateMalformedStringException|RuntimeException
     * @return mixed
     */
    private function resolveSpecialType(
        string $typeName,
        mixed $value,
        string $paramName = ''
    ): mixed {
        if (is_a($typeName, DateTimeInterface::class, true)) {
            return $this->resolveTimestamp($typeName, $value, $paramName);
        }

        if (is_a($typeName, EntityIdentifierInterface::class, true)) {
            /** @var class-string<EntityIdentifierInterface> $identifierClass */
            $identifierClass = interface_exists($typeName) ? EntityIdentifier::class : $typeName;

            return is_string($value)
                ? $identifierClass::fromString($value)
                : $identifierClass::fromString((string) $this->aggregateId);
        }

        if ($typeName === EventVersion::class) {
            return is_numeric($value) ? EventVersion::fromInt((int) $value) : EventVersion::new();
        }

        if (is_a($typeName, BackedEnum::class, true)) {
            /** @var class-string<BackedEnum> $enumClass */
            $enumClass = $typeName;

            return $this->resolveBackedEnum($enumClass, $value, $paramName);
        }

        return $value;
    }

    /**
     * A stored timestamp, read as UTC, as the declared type where that type
     * can be built from what is stored.
     *
     * `resolveSpecialType()` used to know the exact class `DateTimeImmutable`
     * and nothing else, so a parameter typed `DateTimeInterface` — or
     * against this package's own `OccurredOn`, the type most likely to be
     * reached for — fell through and was handed the raw string, producing a
     * TypeError at construction.
     *
     * The zone is stated, never inherited. A stored timestamp has
     * meant UTC since  normalised the write side, but the format
     * carries no offset to say so, so parsing it without a zone reads it in
     * whatever `date.timezone` the process happens to have — and every event
     * then denotes a different moment than the one written, drifting again on
     * each read-and-store cycle. Widening the accepted types must not
     * reintroduce that, which is why every one of them goes through here.
     *
     * @param string $typeName
     * @param mixed $value
     * @param string $paramName
     * @throws DateMalformedStringException|RuntimeException
     * @return DateTimeInterface
     */
    private function resolveTimestamp(
        string $typeName,
        mixed $value,
        string $paramName
    ): DateTimeInterface {
        $datetime = is_string($value) ? $value : 'now';
        $utc = new DateTimeZone('UTC');

        if ($this->canBuildTimestamp($typeName)) {
            /** @var class-string<DateTimeImmutable> $typeName */
            return new $typeName($datetime, $utc);
        }

        // The declared type is an interface, or an abstract stand-in, that
        // OccurredOn satisfies — which is what this package would have written
        // in the first place. Mutable DateTime lands here too, deliberately:
        // an event is a historical fact, and handing one a mutable timestamp
        // invites code that edits the past in place.
        if (is_a(OccurredOn::class, $typeName, true)) {
            return new OccurredOn($datetime, $utc);
        }

        throw new RuntimeException(sprintf(
            "Cannot resolve '%s' for event class %s: %s is a timestamp type this class cannot build from "
            . 'a stored string, and OccurredOn does not satisfy it. Give the event a factory '
            . '(%s::getFactory(), or an injected EventFactoryInterface).',
            $paramName,
            $this->eventClass,
            $typeName,
            $this->eventClass
        ));
    }

    /**
     * Whether the declared timestamp class can be constructed from the two
     * arguments this class has: the stored string and the UTC zone.
     *
     * @param string $typeName
     * @return bool
     */
    private function canBuildTimestamp(
        string $typeName
    ): bool {
        if ($typeName !== DateTimeImmutable::class
            && !is_subclass_of($typeName, DateTimeImmutable::class)) {
            return false;
        }

        $reflection = new ReflectionClass($typeName);

        if (!$reflection->isInstantiable()) {
            return false;
        }

        $constructor = $reflection->getConstructor();

        // Never null here — DateTimeImmutable has one and a subclass that
        // declares none inherits it — but a class with its own required second
        // argument, or fewer than two parameters, cannot take what is passed.
        return $constructor !== null
            && $constructor->getNumberOfRequiredParameters() <= 1
            && $constructor->getNumberOfParameters() >= 2;
    }

    /**
     * A backed enum from its stored backing value.
     *
     * Backed enums in event payloads are ordinary and used to fall through to
     * the untouched-value branch, so the constructor was handed the raw string
     *.
     *
     * A value the enum does not have fails as this class's error rather than
     * as a bare `ValueError` from `from()`: the reader's question is "which
     * stored event will not load, and why", and `from()` answers neither half.
     *
     * @param class-string<BackedEnum> $enumClass
     * @param mixed $value
     * @param string $paramName
     * @throws RuntimeException
     * @return BackedEnum
     */
    private function resolveBackedEnum(
        string $enumClass,
        mixed $value,
        string $paramName
    ): BackedEnum {
        $backing = (string) (new ReflectionEnum($enumClass))->getBackingType();

        $resolved = null;

        if ($backing === 'int' && is_numeric($value)) {
            $resolved = $enumClass::tryFrom((int) $value);
        } elseif ($backing === 'string' && is_scalar($value)) {
            $resolved = $enumClass::tryFrom((string) $value);
        }

        if ($resolved === null) {
            throw new RuntimeException(sprintf(
                "Cannot resolve '%s' for event class %s: %s is a backed enum with no case for the stored "
                . 'value %s.',
                $paramName,
                $this->eventClass,
                $enumClass,
                is_scalar($value) ? var_export($value, true) : gettype($value)
            ));
        }

        return $resolved;
    }

    /**
     * Migrates an event payload to ensure it conforms to the latest schema.
     *
     * @param array<string, mixed> $payload
     * @param int $schemaVersion The schema the payload was written at — see
     *        `payloadSchemaVersion()`, and never the stream version.
     * @return array<string, mixed>
     */
    private function migratePayload(
        array $payload,
        int $schemaVersion
    ): array {
        $eventClass = $this->eventClass;

        $latestVersion = self::declaredSchemaVersion($eventClass) ?? 1;

        if ($schemaVersion >= $latestVersion) {
            return $payload;
        }

        if (method_exists($eventClass, 'migratePayload')) {
            /** @var callable-string $eventClass */
            $migrated = $eventClass::migratePayload($payload, $schemaVersion, $latestVersion);

            return $this->assertPayload($migrated, $eventClass);
        }

        return $payload;
    }

    /**
     * Which schema this entry's payload was written at.
     *
     * The entry's own answer wins when it has one — an adapter that keeps the
     * schema version in a column knows better than the payload does. Otherwise
     * the payload's own marker, and otherwise the first schema: a row written
     * before this marker existed carries nothing, and "as old as the history
     * goes" is the only answer that cannot silently skip a migration.
     *
     * What this must never be is `$version`. That is the event's place in its
     * aggregate's stream, and comparing it against `getLatestSchemaVersion()`
     * is comparing a position against a shape — right by coincidence
     * at position 1, wrong from position 2 on, and silently so: an event at
     * position 27 compared 27 against schema 2, called itself newer than the
     * latest schema, and skipped the migration it needed.
     *
     * @return int
     */
    private function payloadSchemaVersion(): int
    {
        if ($this->schemaVersion !== null) {
            return $this->schemaVersion;
        }

        $stored = $this->payload[self::SCHEMA_VERSION_KEY] ?? null;

        return is_numeric($stored) ? (int) $stored : 1;
    }

    /**
     * What an upcasting hook handed back, checked before it is treated as a
     * payload.
     *
     * The alternative is letting a wrong return travel: a string reaches the
     * event factory and fails there, naming a class that did nothing wrong,
     * and a list quietly hydrates an event with every property at its default.
     *
     * @param mixed $migrated
     * @param string $eventClass
     * @throws RuntimeException
     * @return array<string, mixed>
     */
    private function assertPayload(
        mixed $migrated,
        string $eventClass
    ): array {
        if (!is_array($migrated)) {
            throw new RuntimeException(sprintf(
                '%s::migratePayload() must return an array, %s given.',
                $eventClass,
                get_debug_type($migrated)
            ));
        }

        $payload = [];

        foreach ($migrated as $key => $value) {
            if (!is_string($key)) {
                throw new RuntimeException(sprintf(
                    '%s::migratePayload() must return a payload keyed by property name; got key %s.',
                    $eventClass,
                    get_debug_type($key)
                ));
            }

            $payload[$key] = $value;
        }

        return $payload;
    }
}
