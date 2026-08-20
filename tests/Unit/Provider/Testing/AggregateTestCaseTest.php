<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Provider\Testing;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Aggregate\AggregateId;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventEntry;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventStream;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcingCore\Provider\Testing\AggregateTestCase;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The helper's own tests.
 *
 * A test helper that is wrong is worse than no helper, because it fails
 * quietly in the direction of passing — a green suite that proves nothing. So
 * the cases that matter here are the **failing** ones: every assertion the
 * helper makes is exercised in the direction where it must refuse.
 *
 * The scenario object is driven directly rather than by subclassing, because a
 * failure inside a nested TestCase has to be caught and inspected rather than
 * ending this test.
 */
#[CoversClass(AggregateRoot::class)]
#[CoversClass(EventStream::class)]
#[CoversClass(EventEntry::class)]
#[CoversClass(EventVersion::class)]
#[CoversClass(EventId::class)]
#[CoversClass(OccurredOn::class)]
#[CoversClass(SourceEvent::class)]
#[CoversClass(AggregateId::class)]
#[CoversClass(EntityIdentifier::class)]
#[UsesClass(\DomainFlow\EventSourcing\Event\EventMetadata::class)]
final class AggregateTestCaseTest extends TestCase
{
    public function test_a_command_on_replayed_history_emits_the_expected_event(): void
    {
        $id = EntityIdentifier::fromString('order-1');

        $this->scenario()
            ->given(new OrderPlaced($id, 'widget'), new OrderPaid($id))
            ->when(static fn (TestOrder $order): mixed => $order->ship())
            ->then(new OrderShipped($id));

        $this->addToAssertionCount(1);
    }

    /**
     * The history really is replayed, not merely counted: the aggregate's
     * state after `given()` has to be what the events say it is.
     */
    public function test_given_replays_history_into_aggregate_state(): void
    {
        $id = EntityIdentifier::fromString('order-2');

        $this->scenario()
            ->given(new OrderPlaced($id, 'anvil'), new OrderPaid($id))
            ->thenState(static function (TestOrder $order): void {
                TestCase::assertSame('anvil', $order->sku);
                TestCase::assertTrue($order->paid);
                TestCase::assertFalse($order->shipped);
            });

        $this->addToAssertionCount(1);
    }

    /**
     * A consumer never writes a version number.
     * The helper numbers the history, and the aggregate numbers what it emits
     * — and the second half is exactly the relevant defect class, so the helper
     * checks it rather than ignoring it.
     */
    public function test_the_helper_numbers_the_history_and_checks_what_the_aggregate_numbers(): void
    {
        $id = EntityIdentifier::fromString('order-3');

        $scenario = $this->scenario()
            ->given(new OrderPlaced($id, 'widget'), new OrderPaid($id))
            ->when(static fn (TestOrder $order): mixed => $order->ship());

        $emitted = $scenario->aggregate()->getUncommittedEvents();

        $this->assertCount(1, $emitted);
        $this->assertSame(3, $emitted[0]->getVersion()->toInt(), 'Two events of history, so the new one is version 3.');
    }

    /**
     * `eventId` and `occurredOn` are non-deterministic and are not what an
     * aggregate test is about. If they were compared, no `then()` could ever
     * pass.
     */
    public function test_eventId_and_occurredOn_are_ignored_in_the_comparison(): void
    {
        $id = EntityIdentifier::fromString('order-4');

        // A deliberately different id and an obviously wrong timestamp.
        $expected = new OrderShipped(
            $id,
            EventId::generate(),
            new DateTimeImmutable('1999-01-01 00:00:00')
        );

        $this->scenario()
            ->given(new OrderPlaced($id, 'widget'), new OrderPaid($id))
            ->when(static fn (TestOrder $order): mixed => $order->ship())
            ->then($expected);

        $this->addToAssertionCount(1);
    }

    public function test_a_wrong_payload_fails_and_the_message_shows_the_difference(): void
    {
        $id = EntityIdentifier::fromString('order-5');

        $failure = $this->captureFailure(function () use ($id): void {
            $this->scenario()
                ->given(new OrderPlaced($id, 'widget'))
                ->when(static fn (TestOrder $order): mixed => $order->rename('actual-name'))
                ->then(new OrderRenamed($id, 'expected-name'));
        });

        $text = $this->failureText($failure);

        $this->assertStringContainsString('expected-name', $text);
        $this->assertStringContainsString('actual-name', $text);
    }

    public function test_a_wrong_event_class_fails(): void
    {
        $id = EntityIdentifier::fromString('order-6');

        $failure = $this->captureFailure(function () use ($id): void {
            $this->scenario()
                ->given(new OrderPlaced($id, 'widget'), new OrderPaid($id))
                ->when(static fn (TestOrder $order): mixed => $order->ship())
                ->then(new OrderPaid($id));
        });

        $text = $this->failureText($failure);

        $this->assertStringContainsString('OrderPaid', $text);
        $this->assertStringContainsString('OrderShipped', $text);
    }

    public function test_too_few_expected_events_fails(): void
    {
        $id = EntityIdentifier::fromString('order-7');

        $failure = $this->captureFailure(function () use ($id): void {
            $this->scenario()
                ->given(new OrderPlaced($id, 'widget'), new OrderPaid($id))
                ->when(static fn (TestOrder $order): mixed => $order->ship())
                ->then();
        });

        $this->assertNotSame('', $failure->getMessage());
    }

    public function test_thenFails_accepts_the_expected_exception(): void
    {
        $id = EntityIdentifier::fromString('order-8');

        $this->scenario()
            ->given(new OrderPlaced($id, 'widget'))
            ->when(static fn (TestOrder $order): mixed => $order->ship())
            ->thenFails(OrderNotPaid::class);

        $this->addToAssertionCount(1);
    }

    public function test_thenFails_can_also_pin_the_message(): void
    {
        $id = EntityIdentifier::fromString('order-9');

        $this->scenario()
            ->given(new OrderPlaced($id, 'widget'))
            ->when(static fn (TestOrder $order): mixed => $order->ship())
            ->thenFails(OrderNotPaid::class, 'not been paid');

        $this->addToAssertionCount(1);
    }

    public function test_thenFails_refuses_the_wrong_exception_class(): void
    {
        $id = EntityIdentifier::fromString('order-10');

        $failure = $this->captureFailure(function () use ($id): void {
            $this->scenario()
                ->given(new OrderPlaced($id, 'widget'))
                ->when(static fn (TestOrder $order): mixed => $order->ship())
                // Deliberately unrelated: OrderNotPaid extends RuntimeException,
                // so asserting against that parent would have passed and proved
                // nothing about the check.
                ->thenFails(LogicException::class);
        });

        $this->assertStringContainsString('OrderNotPaid', $this->failureText($failure));
    }

    /**
     * The case that would otherwise be silent: a command that was supposed to
     * be refused and was not. Without this, `thenFails()` on a command that
     * quietly succeeded would pass by doing nothing.
     */
    public function test_thenFails_refuses_a_command_that_did_not_throw(): void
    {
        $id = EntityIdentifier::fromString('order-11');

        $failure = $this->captureFailure(function () use ($id): void {
            $this->scenario()
                ->given(new OrderPlaced($id, 'widget'), new OrderPaid($id))
                ->when(static fn (TestOrder $order): mixed => $order->ship())
                ->thenFails(OrderNotPaid::class);
        });

        $this->assertStringContainsString('did not throw', $failure->getMessage());
    }

    public function test_thenNothingHappened_accepts_an_idempotent_command(): void
    {
        $id = EntityIdentifier::fromString('order-12');

        $this->scenario()
            ->given(new OrderPlaced($id, 'widget'), new OrderPaid($id), new OrderShipped($id))
            ->when(static fn (TestOrder $order): mixed => $order->ship())
            ->thenNothingHappened();

        $this->addToAssertionCount(1);
    }

    public function test_thenNothingHappened_refuses_a_command_that_emitted(): void
    {
        $id = EntityIdentifier::fromString('order-13');

        $failure = $this->captureFailure(function () use ($id): void {
            $this->scenario()
                ->given(new OrderPlaced($id, 'widget'), new OrderPaid($id))
                ->when(static fn (TestOrder $order): mixed => $order->ship())
                ->thenNothingHappened();
        });

        $this->assertStringContainsString('OrderShipped', $this->failureText($failure));
    }

    /**
     * An exception the test did not ask about must not be reported as "the
     * events did not match" — that sends the reader looking at the payload
     * when the aggregate never got that far.
     */
    public function test_then_reports_an_unexpected_exception_as_itself(): void
    {
        $id = EntityIdentifier::fromString('order-14');

        $failure = $this->captureFailure(function () use ($id): void {
            $this->scenario()
                ->given(new OrderPlaced($id, 'widget'))
                ->when(static fn (TestOrder $order): mixed => $order->ship())
                ->then(new OrderShipped($id));
        });

        $this->assertStringContainsString('OrderNotPaid', $failure->getMessage());
        $this->assertStringContainsString('thenFails', $failure->getMessage(), 'And it must point at the method that expects a throw.');
    }

    public function test_a_command_may_be_run_against_an_aggregate_with_no_history(): void
    {
        $id = EntityIdentifier::fromString('order-15');

        $this->scenario()
            ->when(static fn (TestOrder $order): mixed => $order->place($id, 'fresh'))
            ->then(new OrderPlaced($id, 'fresh'));

        $this->addToAssertionCount(1);
    }

    public function test_then_before_when_is_refused_rather_than_silently_passing(): void
    {
        $id = EntityIdentifier::fromString('order-16');

        $failure = $this->captureFailure(function () use ($id): void {
            $this->scenario()
                ->given(new OrderPlaced($id, 'widget'))
                ->then(new OrderShipped($id));
        });

        $this->assertStringContainsString('when()', $failure->getMessage());
    }

    /**
     * Everything a reader is shown when the assertion fails: the message *and*
     * the diff, which PHPUnit keeps on the comparison failure rather than in
     * `getMessage()`. Asserting on the message alone would have missed the half
     * that carries the payload.
     */
    private function failureText(
        AssertionFailedError $failure
    ): string {
        $comparison = $failure instanceof ExpectationFailedException
            ? $failure->getComparisonFailure()
            : null;

        return $failure->getMessage() . "\n" . ($comparison?->getDiff() ?? '');
    }

    /**
     * Runs the callable and returns the assertion failure it produced,
     * failing this test if it produced none.
     */
    private function captureFailure(
        callable $scenario
    ): AssertionFailedError {
        try {
            $scenario();
        } catch (AssertionFailedError $failure) {
            return $failure;
        }

        $this->fail('The scenario was expected to fail its assertion and did not.');
    }

    private function scenario(): OrderScenario
    {
        return new OrderScenario('scenario');
    }
}

/**
 * A concrete AggregateTestCase, driven directly by the tests above.
 */
final class OrderScenario extends AggregateTestCase
{
    protected function aggregateClass(): string
    {
        return TestOrder::class;
    }
}

final class OrderNotPaid extends RuntimeException
{
}

final class TestOrder extends AggregateRoot
{
    public string $sku = '';
    public bool $paid = false;
    public bool $shipped = false;
    public string $name = '';

    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new self();
    }

    public function place(
        EntityIdentifierInterface $id,
        string $sku
    ): void {
        $this->applyEvent(new OrderPlaced($id, $sku));
    }

    public function ship(): void
    {
        if ($this->shipped) {
            return;
        }

        if (!$this->paid) {
            throw new OrderNotPaid('This order has not been paid.');
        }

        $this->applyEvent(new OrderShipped(EntityIdentifier::fromString((string) $this->aggregateIdOfRecord)));
    }

    public function rename(
        string $name
    ): void {
        $this->applyEvent(new OrderRenamed(EntityIdentifier::fromString((string) $this->aggregateIdOfRecord), $name));
    }

    private ?EntityIdentifierInterface $aggregateIdOfRecord = null;

    protected function applyOrderPlaced(
        OrderPlaced $event
    ): void {
        $this->sku = $event->sku;
        $this->aggregateIdOfRecord = $event->getAggregateId();
    }

    protected function applyOrderPaid(
        OrderPaid $event
    ): void {
        $this->paid = true;
        $this->aggregateIdOfRecord = $event->getAggregateId();
    }

    protected function applyOrderShipped(
        OrderShipped $event
    ): void {
        $this->shipped = true;
        $this->aggregateIdOfRecord = $event->getAggregateId();
    }

    protected function applyOrderRenamed(
        OrderRenamed $event
    ): void {
        $this->name = $event->name;
        $this->aggregateIdOfRecord = $event->getAggregateId();
    }
}

final class OrderPlaced extends SourceEvent
{
    public function __construct(
        ?EntityIdentifierInterface $aggregateId,
        public readonly string $sku = '',
        ?EntityIdentifierInterface $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
    }

    public function toArray(): array
    {
        return parent::toArray() + ['sku' => $this->sku];
    }
}

final class OrderPaid extends SourceEvent
{
    public function __construct(
        ?EntityIdentifierInterface $aggregateId,
        ?EntityIdentifierInterface $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
    }
}

final class OrderShipped extends SourceEvent
{
    public function __construct(
        ?EntityIdentifierInterface $aggregateId,
        ?EntityIdentifierInterface $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
    }
}

final class OrderRenamed extends SourceEvent
{
    public function __construct(
        ?EntityIdentifierInterface $aggregateId,
        public readonly string $name = '',
        ?EntityIdentifierInterface $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
    }

    public function toArray(): array
    {
        return parent::toArray() + ['name' => $this->name];
    }
}
