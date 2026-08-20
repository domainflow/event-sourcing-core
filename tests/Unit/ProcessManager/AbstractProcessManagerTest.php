<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\ProcessManager;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventDispatcher;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\ProcessManager\AbstractProcessManager;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerState;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerStateEnum;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractProcessManager::class)]
#[CoversClass(EntityIdentifier::class)]
#[CoversClass(EventDispatcher::class)]
#[CoversClass(EventId::class)]
#[CoversClass(EventVersion::class)]
#[CoversClass(OccurredOn::class)]
#[CoversClass(SourceEvent::class)]
#[CoversClass(ProcessManagerState::class)]
#[CoversClass(ProcessManagerStateEnum::class)]
final class AbstractProcessManagerTest extends TestCase
{
    public function test_getSubscribedTo(): void
    {
        $this->assertSame(
            [DummyOrderCreated::class, DummyOrderShipped::class],
            DummyOrderProcessManager::getSubscribedTo()
        );
    }

    public function test_handleStartsTheProcessOnFirstEvent(): void
    {
        $processManager = new DummyOrderProcessManager();
        $aggregateId = EntityIdentifier::fromString('order-1');

        $processManager->handle(new DummyOrderCreated($aggregateId, null));

        $this->assertFalse($processManager->isComplete());
        $this->assertSame(ProcessManagerStateEnum::PROCESSING, $processManager->getState()->getStatus());
        $this->assertSame((string) $aggregateId, (string) $processManager->getState()->getProcessId());
        $this->assertSame(['orderId' => (string) $aggregateId], $processManager->getState()->getData());
    }

    public function test_handleCompletesTheProcessOnFinalEvent(): void
    {
        $processManager = new DummyOrderProcessManager();
        $aggregateId = EntityIdentifier::fromString('order-1');

        $processManager->handle(new DummyOrderCreated($aggregateId, null));
        $processManager->handle(new DummyOrderShipped($aggregateId, null));

        $this->assertTrue($processManager->isComplete());
        $this->assertSame(ProcessManagerStateEnum::COMPLETED, $processManager->getState()->getStatus());
    }

    public function test_startTwiceThrows(): void
    {
        $processManager = new DummyOrderProcessManager();
        $aggregateId = EntityIdentifier::fromString('order-1');

        $processManager->start(new DummyOrderCreated($aggregateId, null));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf('%s has already been started.', DummyOrderProcessManager::class));

        $processManager->start(new DummyOrderCreated($aggregateId, null));
    }

    public function test_getStateBeforeStartThrows(): void
    {
        $processManager = new DummyOrderProcessManager();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf('%s has not been started yet.', DummyOrderProcessManager::class));

        $processManager->getState();
    }

    public function test_isCompleteIsFalseBeforeStart(): void
    {
        $processManager = new DummyOrderProcessManager();

        $this->assertFalse($processManager->isComplete());
    }

    public function test_hasTimedOutIsFalseBeforeStart(): void
    {
        $processManager = new DummyOrderProcessManager();

        $this->assertFalse($processManager->hasTimedOut());
    }

    public function test_hasTimedOutIsFalseWithoutTimeoutSet(): void
    {
        $processManager = new DummyOrderProcessManager();
        $processManager->start(new DummyOrderCreated(EntityIdentifier::fromString('order-1'), null));

        $this->assertFalse($processManager->hasTimedOut());
    }

    public function test_hasTimedOutReflectsConfiguredTimeout(): void
    {
        $processManager = new DummyOrderProcessManager();
        $processManager->start(new DummyOrderCreated(EntityIdentifier::fromString('order-1'), null));
        $processManager->exposeSetTimeout(new DateTimeImmutable('2024-01-01 12:00:00'));

        $this->assertFalse($processManager->hasTimedOut(new DateTimeImmutable('2024-01-01 11:59:59')));
        $this->assertTrue($processManager->hasTimedOut(new DateTimeImmutable('2024-01-01 12:00:01')));
    }

    public function test_fromStateReconstitutesExistingState(): void
    {
        $processManager = new DummyOrderProcessManager();
        $aggregateId = EntityIdentifier::fromString('order-1');
        $processManager->handle(new DummyOrderCreated($aggregateId, null));

        $reconstituted = DummyOrderProcessManager::fromState($processManager->getState());

        $this->assertSame(ProcessManagerStateEnum::PROCESSING, $reconstituted->getState()->getStatus());
        $this->assertFalse($reconstituted->isComplete());
    }

    public function test_onEventCanMarkTheProcessFailed(): void
    {
        $processManager = new DummyOrderProcessManager();
        $aggregateId = EntityIdentifier::fromString('order-1');

        $processManager->handle(new DummyOrderCreated($aggregateId, null));
        $processManager->handle(new DummyOrderCancelled($aggregateId, null));

        $this->assertTrue($processManager->isComplete());
        $this->assertSame(ProcessManagerStateEnum::FAILED, $processManager->getState()->getStatus());
    }

    public function test_canBeRegisteredDirectlyWithEventDispatcher(): void
    {
        $dispatcher = new EventDispatcher();
        $processManager = new DummyOrderProcessManager();
        $dispatcher->register($processManager);

        $aggregateId = EntityIdentifier::fromString('order-1');
        $dispatcher->dispatch(new DummyOrderCreated($aggregateId, null));
        $dispatcher->dispatch(new DummyOrderShipped($aggregateId, null));

        $this->assertTrue($processManager->isComplete());
        $this->assertSame(ProcessManagerStateEnum::COMPLETED, $processManager->getState()->getStatus());
    }

    public function test_fromStateWithNullStateIsFreshProcess(): void
    {
        $reconstituted = DummyOrderProcessManager::fromState(null);

        $this->assertFalse($reconstituted->isComplete());
        $this->assertFalse($reconstituted->hasTimedOut());
    }

    /**
     * A saga registered directly with an EventDispatcher is handed every event
     * of the types it subscribed to — including the ones belonging to other
     * instances of the same process. Only `start()` ever looked at the
     * correlation, so from the second event on the instance handled whatever
     * arrived: an event of order B could complete the saga watching order A,
     * and the state it wrote named A while the decision was made about B.
     */
    public function test_anEventForAnotherProcessIsNotHandled(): void
    {
        $processManager = new DummyOrderProcessManager();
        $processManager->handle(new DummyOrderCreated(EntityIdentifier::fromString('order-a'), null));

        $processManager->handle(new DummyOrderShipped(EntityIdentifier::fromString('order-b'), null));

        $this->assertFalse($processManager->isComplete(), 'An event of another process completed this one.');
        $this->assertSame(ProcessManagerStateEnum::PROCESSING, $processManager->getState()->getStatus());
    }

    /**
     * A finished process is finished. A redelivered event — the ordinary case
     * in any at-least-once transport — used to run `onEvent()` again, which is
     * where the compensating action gets taken twice and where a COMPLETED
     * saga could be talked into FAILED long after the fact.
     */
    public function test_anEventAfterTheProcessHasFinishedIsNotHandled(): void
    {
        $processManager = new DummyOrderProcessManager();
        $aggregateId = EntityIdentifier::fromString('order-1');

        $processManager->handle(new DummyOrderCreated($aggregateId, null));
        $processManager->handle(new DummyOrderShipped($aggregateId, null));

        $processManager->handle(new DummyOrderCancelled($aggregateId, null));

        $this->assertSame(
            ProcessManagerStateEnum::COMPLETED,
            $processManager->getState()->getStatus(),
            'An event that arrived after the process finished changed its outcome.'
        );
    }

    /**
     * The guard compares identifiers as strings rather than through
     * `equals()`, which is class-strict: a state comes back from storage
     * carrying an `EntityIdentifier` while `correlationId()` may well hand
     * back a domain-specific identifier class for the same process. Comparing
     * the objects would call every such saga's own events foreign and quietly
     * stop handling them.
     */
    public function test_anEventWhoseCorrelationIdIsADifferentIdentifierClassIsStillHandled(): void
    {
        $processManager = TypedIdProcessManager::fromState(
            new ProcessManagerState(EntityIdentifier::fromString('order-1'), ProcessManagerStateEnum::PROCESSING)
        );

        $processManager->handle(new DummyOrderShipped(EntityIdentifier::fromString('order-1'), null));

        $this->assertTrue($processManager->isComplete(), 'A saga stopped recognising its own events.');
    }
}

# dummy classes
final class DummyOrderProcessManager extends AbstractProcessManager
{
    public static function getSubscribedTo(): array
    {
        return [DummyOrderCreated::class, DummyOrderShipped::class];
    }

    public static function correlationId(
        DomainEventInterface $event
    ): EntityIdentifierInterface {
        return $event->getAggregateId();
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    protected function createInitialData(
        DomainEventInterface $event
    ): array {
        return ['orderId' => (string) $event->getAggregateId()];
    }

    protected function onEvent(
        DomainEventInterface $event
    ): void {
        if ($event instanceof DummyOrderShipped) {
            $this->markCompleted();
        }
        if ($event instanceof DummyOrderCancelled) {
            $this->markFailed();
        }
    }

    public function exposeSetTimeout(
        DateTimeImmutable $timeout
    ): void {
        $this->setTimeout($timeout);
    }
}

/**
 * Hands back its own identifier class, as a saga keyed on a domain identifier
 * does.
 */
final class TypedIdProcessManager extends AbstractProcessManager
{
    public static function getSubscribedTo(): array
    {
        return [DummyOrderShipped::class];
    }

    public static function correlationId(
        DomainEventInterface $event
    ): EntityIdentifierInterface {
        return OrderNumber::fromString((string) $event->getAggregateId());
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    protected function createInitialData(
        DomainEventInterface $event
    ): array {
        return [];
    }

    protected function onEvent(
        DomainEventInterface $event
    ): void {
        $this->markCompleted();
    }
}

/**
 * An identifier class of the consumer's own, as opposed to this package's
 * default — the case `EntityIdentifier::equals()` answers "not equal" to.
 */
final class OrderNumber implements EntityIdentifierInterface
{
    public function __construct(
        private readonly string $value
    ) {
    }

    public static function fromString(
        string $value
    ): self {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(
        EntityIdentifierInterface $other
    ): bool {
        return $other instanceof self && $other->value === $this->value;
    }
}

final class DummyOrderCreated extends SourceEvent
{
}

final class DummyOrderShipped extends SourceEvent
{
}

final class DummyOrderCancelled extends SourceEvent
{
}
