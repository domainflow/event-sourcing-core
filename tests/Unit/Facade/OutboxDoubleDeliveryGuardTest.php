<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Facade;

use DomainFlow\EventSourcing\Concurrency\ConcurrencyCheckingStorage;
use DomainFlow\EventSourcing\Concurrency\ConcurrencyCheckStrategyInterface;
use DomainFlow\EventSourcing\Exception\DoubleDeliveryException;
use DomainFlow\EventSourcing\Facade\EventSourcingFacade;
use DomainFlow\EventSourcing\Interface\EventDispatcherInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\OutboxBackedStorageInterface;
use DomainFlow\EventSourcing\Repository\AggregateRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;

/**
 * The outbox and an inline dispatcher are two delivery paths and they are not
 * exclusive: with both in place every event goes out twice, for as
 * long as the configuration stands, and nothing errors. The three adapter
 * READMEs warned about it in prose and nothing enforced it.
 *
 * The mistake is a *subtraction*: adopting the outbox means removing a
 * dispatcher that is already there and still works. Nothing fails at that
 * moment, and the duplicate arrives at the consumer rather than at the writer.
 */
#[CoversClass(EventSourcingFacade::class)]
#[CoversClass(DoubleDeliveryException::class)]
#[CoversClass(ConcurrencyCheckingStorage::class)]
#[UsesClass(AggregateRepository::class)]
final class OutboxDoubleDeliveryGuardTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function test_aStorageDeliveringThroughAnOutboxRefusesAnInlineDispatcher(): void
    {
        $this->expectException(DoubleDeliveryException::class);

        new EventSourcingFacade(
            $this->outboxBackedStorage(true),
            dispatcher: $this->createStub(EventDispatcherInterface::class)
        );
    }

    /**
     * The case that decides whether the guard is worth having.
     *
     * `enableConcurrencyCheck()` wraps the storage, and a consumer may hand in
     * an already-wrapped one. A decorator that does not forward the capability
     * turns this guard into a check that silently passes — which is worse than
     * no guard at all, because the configuration then looks verified.
     *
     * @throws Exception
     */
    public function test_theGuardIsNotBlindedByTheConcurrencyDecorator(): void
    {
        $decorated = new ConcurrencyCheckingStorage(
            $this->outboxBackedStorage(true),
            $this->createStub(ConcurrencyCheckStrategyInterface::class)
        );

        $this->expectException(DoubleDeliveryException::class);

        new EventSourcingFacade($decorated, dispatcher: $this->createStub(EventDispatcherInterface::class));
    }

    /**
     * An adapter that has an outbox seam but is not using it must not be
     * refused: the guard is about the configuration in force, not about which
     * classes are installed.
     *
     * @throws Exception
     */
    public function test_aStorageWithAnIdleOutboxSeamKeepsItsDispatcher(): void
    {
        $facade = new EventSourcingFacade(
            $this->outboxBackedStorage(false),
            dispatcher: $this->createStub(EventDispatcherInterface::class)
        );

        $this->assertInstanceOf(EventSourcingFacade::class, $facade);
    }

    /**
     * An out-of-tree adapter that says nothing about an outbox keeps working
     * exactly as before. The guard must not turn silence into a refusal —
     * that would break every adapter outside these four repositories, which is
     * the reason the capability is a separate interface rather than a method
     * on `EventStorageInterface`.
     *
     * @throws Exception
     */
    public function test_aStorageWithoutTheCapabilityIsUnaffected(): void
    {
        $facade = new EventSourcingFacade(
            $this->createStub(EventStorageInterface::class),
            dispatcher: $this->createStub(EventDispatcherInterface::class)
        );

        $this->assertInstanceOf(EventSourcingFacade::class, $facade);
    }

    /**
     * The arrangement the outbox exists for: the relay delivers, so the facade
     * has no dispatcher and there is nothing to refuse.
     *
     * @throws Exception
     */
    public function test_anOutboxWithoutAnInlineDispatcherIsTheWholePoint(): void
    {
        $facade = new EventSourcingFacade($this->outboxBackedStorage(true));

        $this->assertInstanceOf(EventSourcingFacade::class, $facade);
    }

    /**
     * @param bool $delivers
     * @throws Exception
     * @return EventStorageInterface&OutboxBackedStorageInterface
     */
    private function outboxBackedStorage(
        bool $delivers
    ): EventStorageInterface&OutboxBackedStorageInterface {
        $storage = $this->createStubForIntersectionOfInterfaces([
            EventStorageInterface::class,
            OutboxBackedStorageInterface::class,
        ]);
        $storage->method('deliversThroughOutbox')->willReturn($delivers);

        return $storage;
    }
}
