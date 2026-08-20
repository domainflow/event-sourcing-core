<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Event;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventMetadata;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Trait\HasEventMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;
use ReflectionException;
use RuntimeException;

/**
 * Reflection hydration matches payload keys against constructor parameter
 * names, which makes each parameter name part of the stored data format.
 */
#[CoversClass(EventEntry::class)]
#[CoversClass(EventId::class)]
#[CoversClass(EventVersion::class)]
#[CoversClass(OccurredOn::class)]
#[CoversClass(EntityIdentifier::class)]
#[UsesClass(EventMetadata::class)]
#[UsesTrait(HasEventMetadata::class)]
final class EventEntryHydrationRulesTest extends TestCase
{
    /**
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_a_nullable_scalar_parameter_accepts_null(): void
    {
        $event = $this->entryFor(NullableScalarEvent::class, [
            'aggregateId' => 'agg-nullable',
            'note' => null,
            'count' => null,
        ])->toDomainEvent();

        $this->assertInstanceOf(NullableScalarEvent::class, $event);
        $this->assertNull($event->note);
        $this->assertNull($event->count);
    }

    /**
     * A non-null value still has to be the declared type. The fix must widen
     * what null does, not what everything else does.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_a_nullable_scalar_parameter_still_rejects_a_wrong_type(): void
    {
        $entry = $this->entryFor(NullableScalarEvent::class, [
            'aggregateId' => 'agg-nullable',
            'note' => ['not', 'a', 'string'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Expected string for 'note', got array");

        $entry->toDomainEvent();
    }

    /**
     * A non-nullable parameter must keep rejecting null. Otherwise the fix
     * turns a loud failure into a TypeError at construction, one frame further
     * from the thing that is actually wrong.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_a_non_nullable_scalar_parameter_still_rejects_null(): void
    {
        $entry = $this->entryFor(NullableScalarEvent::class, [
            'aggregateId' => 'agg-nullable',
            'label' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Expected string for 'label', got NULL");

        $entry->toDomainEvent();
    }

    /**
     * The silent case, made loud. A constructor parameter named `$o` where the
     * payload says `occurredOn` used to be reconstructed with a *fresh*
     * timestamp on every load — the value differed from the stored one by
     * however long the row had been sitting there, and nothing anywhere said
     * so.
     *
     * The rule is deliberately narrow: an unfilled optional parameter is only
     * an error when the payload *also* has keys nobody consumed. That is what
     * distinguishes a misnamed parameter from genuine schema evolution.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_an_optional_parameter_no_key_filled_is_an_error_when_keys_went_unused(): void
    {
        $entry = $this->entryFor(MisnamedParameterEvent::class, [
            'data' => 'kept',
            'occurredOn' => '2026-01-01 00:00:00.000000',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("parameter '\$o'");

        $entry->toDomainEvent();
    }

    /**
     * The same exception has to name the payload key too — a message that says
     * only "parameter $o went unfilled" leaves the reader to guess which of
     * their fields was meant.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_the_mismatch_exception_names_the_unused_payload_key(): void
    {
        $entry = $this->entryFor(MisnamedParameterEvent::class, [
            'data' => 'kept',
            'occurredOn' => '2026-01-01 00:00:00.000000',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('occurredOn');

        $entry->toDomainEvent();
    }

    /**
     * Genuine schema evolution: an old row written before the field existed.
     * Every key the payload has is consumed, so the optional parameter takes
     * its default — which is what defaults and upcasting are for, and must
     * keep working.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_an_optional_parameter_may_default_when_no_key_went_unused(): void
    {
        $event = $this->entryFor(MisnamedParameterEvent::class, [
            'data' => 'kept',
        ])->toDomainEvent();

        $this->assertInstanceOf(MisnamedParameterEvent::class, $event);
        $this->assertSame('kept', $event->data);
    }

    /**
     * `resolveSpecialType()` knew the exact class `DateTimeImmutable` and
     * nothing else, so a parameter typed against the interface fell through to
     * `default: return $value` and was handed the raw string — a TypeError at
     * construction.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_a_parameter_typed_DateTimeInterface_is_reconstructed(): void
    {
        $event = $this->entryFor(WidenedTypesEvent::class, [
            'aggregateId' => 'agg-widened',
            'seenAt' => '2026-03-04 05:06:07.089000',
            'stamp' => '2026-03-04 05:06:07.089000',
            'suit' => 'hearts',
        ])->toDomainEvent();

        $this->assertInstanceOf(WidenedTypesEvent::class, $event);
        $this->assertInstanceOf(DateTimeInterface::class, $event->seenAt);
        $this->assertSame('2026-03-04 05:06:07.089000', $event->seenAt->format(EventEntry::DATE_FORMAT));
    }

    /**
     * The package's own value object is the type most likely to be reached
     * for, which made this the worst of the three omissions.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_a_parameter_typed_OccurredOn_is_reconstructed_as_OccurredOn(): void
    {
        $event = $this->entryFor(WidenedTypesEvent::class, [
            'aggregateId' => 'agg-widened',
            'seenAt' => '2026-03-04 05:06:07.089000',
            'stamp' => '2026-03-04 05:06:07.089000',
            'suit' => 'hearts',
        ])->toDomainEvent();

        $this->assertInstanceOf(WidenedTypesEvent::class, $event);
        $this->assertInstanceOf(OccurredOn::class, $event->stamp);
    }

    /**
     * Backed enums in event payloads are ordinary, and fell through to
     * `default` — the constructor got the string.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_a_backed_enum_parameter_is_reconstructed(): void
    {
        $event = $this->entryFor(WidenedTypesEvent::class, [
            'aggregateId' => 'agg-widened',
            'seenAt' => '2026-03-04 05:06:07.089000',
            'stamp' => '2026-03-04 05:06:07.089000',
            'suit' => 'hearts',
        ])->toDomainEvent();

        $this->assertInstanceOf(WidenedTypesEvent::class, $event);
        $this->assertSame(Suit::Hearts, $event->suit);
    }

    /**
     * UTC handling must not be undone by the widening. A stored timestamp is UTC by
     * contract and the format carries no offset to say so, so every one of the
     * newly-understood types has to state the zone rather than inherit
     * whatever `date.timezone` the reading process has.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_the_widened_timestamp_types_are_read_as_utc(): void
    {
        $runtimeTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('Europe/Berlin');

            $event = $this->entryFor(WidenedTypesEvent::class, [
                'aggregateId' => 'agg-widened',
                'seenAt' => '2026-03-04 05:06:07.089000',
                'stamp' => '2026-03-04 05:06:07.089000',
                'suit' => 'hearts',
            ])->toDomainEvent();

            $this->assertInstanceOf(WidenedTypesEvent::class, $event);

            $expected = new DateTimeImmutable('2026-03-04 05:06:07.089000', new DateTimeZone('UTC'));

            $this->assertSame(
                $expected->getTimestamp(),
                $event->seenAt->getTimestamp(),
                'A DateTimeInterface parameter must denote the instant that was stored, not a Berlin reading of it.'
            );
            $this->assertSame(
                $expected->getTimestamp(),
                $event->stamp->getTimestamp(),
                'And so must an OccurredOn one.'
            );
        } finally {
            date_default_timezone_set($runtimeTimezone);
        }
    }

    /**
     * An enum value the payload carries but the enum does not have. It must
     * fail as this class's own error rather than as a bare ValueError from
     * `from()`, because the caller's question is "which event will not load",
     * and `from()` cannot answer that.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_an_unknown_enum_value_names_the_parameter_and_the_class(): void
    {
        $entry = $this->entryFor(WidenedTypesEvent::class, [
            'aggregateId' => 'agg-widened',
            'seenAt' => '2026-03-04 05:06:07.089000',
            'stamp' => '2026-03-04 05:06:07.089000',
            'suit' => 'swords',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot resolve 'suit'");

        $entry->toDomainEvent();
    }

    /**
     * An int-backed enum is stored as a number, and a number that has survived
     * a round trip through a text column comes back as a string. Both have to
     * resolve, or the type works in the in-memory store and fails in every
     * persistent one.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_an_int_backed_enum_parameter_is_reconstructed_from_either_form(): void
    {
        foreach ([2, '2'] as $stored) {
            $event = $this->entryFor(IntBackedEnumEvent::class, [
                'aggregateId' => 'agg-enum',
                'priority' => $stored,
            ])->toDomainEvent();

            $this->assertInstanceOf(IntBackedEnumEvent::class, $event);
            $this->assertSame(Priority::High, $event->priority);
        }
    }

    /**
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_an_unknown_int_backed_enum_value_is_refused(): void
    {
        $entry = $this->entryFor(IntBackedEnumEvent::class, [
            'aggregateId' => 'agg-enum',
            'priority' => 99,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot resolve 'priority'");

        $entry->toDomainEvent();
    }

    /**
     * A declared timestamp type this class can neither build nor substitute
     * `OccurredOn` for fails as this class's own error, naming the parameter
     * and pointing at the factory seam. Left to itself it would be a TypeError
     * from the event's constructor — one frame further from the decision that
     * caused it, and with nothing in it about what to do instead.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_a_timestamp_type_that_cannot_be_built_names_the_parameter(): void
    {
        $entry = $this->entryFor(AwkwardTimestampEvent::class, [
            'aggregateId' => 'agg-awkward',
            'at' => '2026-03-04 05:06:07.089000',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot resolve 'at'");

        $entry->toDomainEvent();
    }

    /**
     * An abstract timestamp type is the other half of the same guard, and the
     * one that would be a fatal `Error` rather than a catchable failure: `new`
     * on an abstract class does not throw something a consumer can handle.
     *
     * @throws DateMalformedStringException|ReflectionException
     */
    public function test_an_abstract_timestamp_type_is_refused_rather_than_instantiated(): void
    {
        $entry = $this->entryFor(AbstractTimestampEvent::class, [
            'aggregateId' => 'agg-abstract',
            'at' => '2026-03-04 05:06:07.089000',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot resolve 'at'");

        $entry->toDomainEvent();
    }

    /**
     * @param class-string $eventClass
     * @param array<string, mixed> $payload
     * @throws DateMalformedStringException
     */
    private function entryFor(
        string $eventClass,
        array $payload
    ): EventEntry {
        return new EventEntry(
            $eventClass,
            EntityIdentifier::fromString('agg-hydration'),
            EventId::generate(),
            OccurredOn::fromString('2026-01-01 00:00:00.000000'),
            EventVersion::fromInt(1),
            $payload
        );
    }
}

enum Suit: string
{
    case Hearts = 'hearts';
    case Spades = 'spades';
}

/**
 * Optional scalars, which is the shape half the fixtures in this tree use.
 */
final class NullableScalarEvent implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public function __construct(
        private readonly EntityIdentifierInterface $aggregateId,
        public readonly ?string $note = null,
        public readonly ?int $count = null,
        public readonly string $label = 'unlabelled'
    ) {
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->aggregateId;
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return $this->version ?? EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return [
            'aggregateId' => (string) $this->aggregateId,
            'note' => $this->note,
            'count' => $this->count,
            'label' => $this->label,
        ];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

/**
 * The `$o`/`occurredOn` mismatch, exactly as the audit found it.
 */
final class MisnamedParameterEvent implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public function __construct(
        public readonly string $data,
        public readonly ?DateTimeImmutable $o = null
    ) {
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return EntityIdentifier::fromString('agg-hydration');
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return $this->o ?? new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return $this->version ?? EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return ['data' => $this->data];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

/**
 * The three types `resolveSpecialType()` did not know.
 */
final class WidenedTypesEvent implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public function __construct(
        private readonly EntityIdentifierInterface $aggregateId,
        public readonly DateTimeInterface $seenAt,
        public readonly OccurredOn $stamp,
        public readonly Suit $suit
    ) {
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->aggregateId;
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return $this->stamp;
    }

    public function getVersion(): EventVersion
    {
        return $this->version ?? EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return [
            'aggregateId' => (string) $this->aggregateId,
            'seenAt' => $this->seenAt->format(EventEntry::DATE_FORMAT),
            'stamp' => $this->stamp->format(EventEntry::DATE_FORMAT),
            'suit' => $this->suit->value,
        ];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

enum Priority: int
{
    case Low = 1;
    case High = 2;
}

/**
 * A DateTimeImmutable subclass that cannot be built from what is stored — it
 * needs a second required argument this class has no value for.
 */
final class LabelledTimestamp extends DateTimeImmutable
{
    /**
     * @throws DateMalformedStringException
     */
    public function __construct(
        string $datetime,
        public readonly string $label
    ) {
        parent::__construct($datetime, new DateTimeZone('UTC'));
    }
}

final class IntBackedEnumEvent implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public function __construct(
        private readonly EntityIdentifierInterface $aggregateId,
        public readonly Priority $priority
    ) {
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->aggregateId;
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function getVersion(): EventVersion
    {
        return $this->version ?? EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return [
            'aggregateId' => (string) $this->aggregateId,
            'priority' => $this->priority->value,
        ];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

final class AwkwardTimestampEvent implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public function __construct(
        private readonly EntityIdentifierInterface $aggregateId,
        public readonly LabelledTimestamp $at
    ) {
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->aggregateId;
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return $this->at;
    }

    public function getVersion(): EventVersion
    {
        return $this->version ?? EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return [
            'aggregateId' => (string) $this->aggregateId,
            'at' => $this->at->format(EventEntry::DATE_FORMAT),
        ];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}

/**
 * Abstract, so `new` on it would be a fatal Error rather than an exception.
 */
abstract class AbstractTimestamp extends DateTimeImmutable
{
}

final class AbstractTimestampEvent implements DomainEventInterface
{
    use HasEventMetadata;

    protected EventVersion $version;

    public function __construct(
        private readonly EntityIdentifierInterface $aggregateId,
        public readonly AbstractTimestamp $at
    ) {
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->aggregateId;
    }

    public function getOccurredOn(): DateTimeImmutable
    {
        return $this->at;
    }

    public function getVersion(): EventVersion
    {
        return $this->version ?? EventVersion::fromInt(1);
    }

    public function toArray(): array
    {
        return [
            'aggregateId' => (string) $this->aggregateId,
            'at' => $this->at->format(EventEntry::DATE_FORMAT),
        ];
    }

    public function setVersion(
        EventVersion $version
    ): void {
        $this->version = $version;
    }
}
