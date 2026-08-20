<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Testing;

use Closure;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventStream;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * given / when / then for an aggregate.
 *
 * ```php
 * final class OrderTest extends AggregateTestCase
 * {
 *     protected function aggregateClass(): string
 *     {
 *         return Order::class;
 *     }
 *
 *     public function test_shipping_a_paid_order_emits_a_shipment(): void
 *     {
 *         $this->given(new OrderPlaced($id), new OrderPaid($id))
 *              ->when(fn (Order $order) => $order->ship())
 *              ->then(new OrderShipped($id));
 *     }
 *
 *     public function test_shipping_an_unpaid_order_is_refused(): void
 *     {
 *         $this->given(new OrderPlaced($id))
 *              ->when(fn (Order $order) => $order->ship())
 *              ->thenFails(OrderNotPaid::class);
 *     }
 * }
 * ```
 *
 * ## What it needs
 *
 * Nothing but the aggregate. No storage, no container, no infrastructure —
 * `given()` builds the aggregate through `AggregateRoot::reconstitute()`, which
 * is the same call `AggregateRepository` makes, so history is replayed exactly
 * as production replays it. An event that cannot survive that round trip fails
 * here too, which is the point: it would fail on the first real load as well.
 *
 * ## Versions are the helper's business, never yours
 *
 * `given()` numbers the history 1, 2, 3… itself and the aggregate numbers what
 * it emits. **A consumer never writes a version number in a test**, which is
 * the house rule from , and it is not cosmetic: the whole /
 * class of defect existed because no test ever let an aggregate assign its own
 * versions. So `then()` compares the version the aggregate assigned against the
 * one that continues the history, rather than ignoring it.
 *
 * If an expected event does carry an explicit version, that version is honoured
 * and compared as written — for the rare test that is *about* numbering.
 *
 * ## What `then()` compares, and what it deliberately does not
 *
 * The event class, the version, and the payload from `toArray()` **minus
 * `eventId` and `occurredOn`**. Those two are non-deterministic — a fresh uuid
 * and the system clock — so comparing them would mean no `then()` could ever
 * pass, and they are not what an aggregate test is about. Everything else,
 * including the aggregate id, is compared: an event emitted for the wrong
 * aggregate is a real defect and this catches it.
 *
 * @see AggregateTestCase::then() for the failure message, which is most of the
 *      value of this class.
 */
abstract class AggregateTestCase extends TestCase
{
    /**
     * The aggregate under test.
     *
     * @return class-string<AggregateRoot>
     */
    abstract protected function aggregateClass(): string;

    private ?AggregateRoot $aggregate = null;

    /** How many events `given()` replayed, so the next version is known. */
    private int $historyLength = 0;

    private bool $commandWasRun = false;

    private ?Throwable $thrown = null;

    /**
     * The aggregate's history. Versions are assigned here, in order.
     *
     * The events are cloned before being numbered, so a caller may pass the
     * same instance to `given()` and to `then()` without the first call
     * silently changing what the second expects.
     */
    public function given(
        DomainEventInterface ...$events
    ): static {
        $entries = [];
        $version = 0;

        foreach ($events as $event) {
            $numbered = clone $event;
            $numbered->setVersion(EventVersion::fromInt(++$version));
            // Handed over as they are, the way a storage hands back the events
            // it has already reconstructed. `fromDomainEvent()` stood here and
            // made `given()` serialise each event and replay a
            // reflection-built copy — so an event that needs its factory
            // failed a test it would have passed in production, and one whose
            // `toArray()` omits a field replayed without it.
            $entries[] = EventEntry::fromReconstructedEvent($numbered);
        }

        $aggregateClass = $this->aggregateClass();

        $this->aggregate = $aggregateClass::reconstitute(new EventStream($entries));
        $this->historyLength = $version;
        $this->commandWasRun = false;
        $this->thrown = null;

        return $this;
    }

    /**
     * The command under test, as a closure over the aggregate.
     *
     * A throw is captured rather than propagated, because being refused is
     * half of what an aggregate test is for — see `thenFails()`. Anything not
     * asked about is reported by `then()` as itself rather than as a mismatch.
     *
     * @param Closure(AggregateRoot): mixed $command
     */
    public function when(
        Closure $command
    ): static {
        if ($this->aggregate === null) {
            // A command against an aggregate with no history is an ordinary
            // case — creation — so given() is not required first.
            $this->given();
        }

        $this->commandWasRun = true;

        try {
            $command($this->aggregate());
        } catch (Throwable $throwable) {
            $this->thrown = $throwable;
        }

        return $this;
    }

    /**
     * The events the command should have emitted, in order.
     *
     * The failure message is a diff of expected against actual, one row per
     * event, carrying the class, the version and the payload. That message is
     * most of the value of this class: a test helper that says only "arrays
     * are not equal" costs more time than it saves.
     */
    public function then(
        DomainEventInterface ...$expected
    ): void {
        $this->assertCommandWasRun('then()');
        $this->assertNothingWasThrown();

        $actualEvents = $this->aggregate()->getUncommittedEvents();

        $expectedRows = [];

        foreach (array_values($expected) as $index => $event) {
            $expectedRows[] = $this->describe($event, $this->historyLength + $index + 1);
        }

        $actualRows = array_map(
            fn (DomainEventInterface $event): array => $this->describe($event, null),
            array_values($actualEvents)
        );

        $this->assertEquals(
            $expectedRows,
            $actualRows,
            sprintf(
                "The aggregate did not emit the events the test expected.\n"
                . "Compared: event class, version, and the payload from toArray().\n"
                . "Ignored by design: 'eventId' and 'occurredOn', which are not deterministic.",
            )
        );
    }

    /**
     * The command emitted nothing — an idempotent command applied twice, or one
     * whose preconditions are already satisfied.
     *
     * Distinct from `then()` with no arguments only in the message it produces,
     * and worth having for exactly that reason: "nothing happened" is an
     * intended outcome, and a test should say so out loud.
     */
    public function thenNothingHappened(): void
    {
        $this->assertCommandWasRun('thenNothingHappened()');
        $this->assertNothingWasThrown();

        $emitted = array_map(
            static fn (DomainEventInterface $event): string => $event::class,
            $this->aggregate()->getUncommittedEvents()
        );

        $this->assertSame(
            [],
            $emitted,
            'The command was expected to do nothing, and emitted: ' . implode(', ', $emitted)
        );
    }

    /**
     * The command was refused.
     *
     * Half of what aggregate tests are for, and missing from several other
     * libraries' versions of this class. A command that quietly *succeeded*
     * fails here rather than passing by omission.
     *
     * @param class-string<Throwable> $exceptionClass
     * @param string|null $messageContains Optional, for the cases where which
     *        rule refused the command matters as much as that one did.
     */
    public function thenFails(
        string $exceptionClass,
        ?string $messageContains = null
    ): void {
        $this->assertCommandWasRun('thenFails()');

        if ($this->thrown === null) {
            $emitted = array_map(
                static fn (DomainEventInterface $event): string => $event::class,
                $this->aggregate()->getUncommittedEvents()
            );

            $this->fail(sprintf(
                'The command was expected to fail with %s and did not throw at all. It emitted: %s',
                $exceptionClass,
                $emitted === [] ? 'nothing' : implode(', ', $emitted)
            ));
        }

        $this->assertInstanceOf(
            $exceptionClass,
            $this->thrown,
            sprintf(
                'The command failed with %s (%s), not with the expected %s.',
                $this->thrown::class,
                $this->thrown->getMessage(),
                $exceptionClass
            )
        );

        if ($messageContains !== null) {
            $this->assertStringContainsString($messageContains, $this->thrown->getMessage());
        }
    }

    /**
     * For the cases where the interesting outcome is the aggregate's state
     * rather than an emitted event.
     *
     * Usable after `given()` alone, to check that history replays into the
     * state it should.
     *
     * @param Closure(AggregateRoot): void $assert
     */
    public function thenState(
        Closure $assert
    ): void {
        $this->assertNothingWasThrown();

        $assert($this->aggregate());
    }

    /**
     * The aggregate itself, for anything these methods do not cover.
     *
     * An escape hatch on purpose: a helper that cannot be stepped out of ends
     * up growing a method per consumer.
     */
    public function aggregate(): AggregateRoot
    {
        if ($this->aggregate === null) {
            $this->fail('No aggregate yet — call given() or when() first.');
        }

        return $this->aggregate;
    }

    /**
     * One row of the comparison.
     *
     * @param DomainEventInterface $event
     * @param int|null $assumedVersion The version this event should carry if it
     *        does not carry one itself. Null for an event that has already been
     *        through the aggregate and therefore has a real one.
     * @return array<string, mixed>
     */
    private function describe(
        DomainEventInterface $event,
        ?int $assumedVersion
    ): array {
        $payload = $event->toArray();

        // Non-deterministic and not the subject of an aggregate test. Removed
        // rather than nulled, so they cannot reappear in a diff as noise.
        unset($payload['eventId'], $payload['occurredOn'], $payload['version']);

        ksort($payload);

        $version = $event->getVersion();

        return [
            'event' => $event::class,
            'version' => $version->isAssigned() || $assumedVersion === null
                ? $version->toInt()
                : $assumedVersion,
            'payload' => $payload,
        ];
    }

    private function assertCommandWasRun(
        string $method
    ): void {
        if (!$this->commandWasRun) {
            $this->fail(sprintf('%s needs a command to judge — call when() first.', $method));
        }
    }

    /**
     * A throw the test did not ask about is reported as itself.
     *
     * Letting it surface as "the events did not match" would send the reader
     * looking at a payload when the aggregate never reached the point of
     * emitting anything.
     */
    private function assertNothingWasThrown(): void
    {
        if ($this->thrown === null) {
            return;
        }

        $this->fail(sprintf(
            'The command threw %s: %s. If that is the expected outcome, use thenFails().',
            $this->thrown::class,
            $this->thrown->getMessage()
        ));
    }
}
