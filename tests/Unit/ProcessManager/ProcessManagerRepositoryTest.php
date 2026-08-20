<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\ProcessManager;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventId;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\ProcessManager\AbstractProcessManager;
use DomainFlow\EventSourcing\ProcessManager\InMemoryProcessManagerStorage;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerRepository;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerState;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerStateEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(ProcessManagerRepository::class)]
#[CoversClass(InMemoryProcessManagerStorage::class)]
#[CoversClass(AbstractProcessManager::class)]
#[CoversClass(EntityIdentifier::class)]
#[CoversClass(EventId::class)]
#[CoversClass(EventVersion::class)]
#[CoversClass(OccurredOn::class)]
#[CoversClass(SourceEvent::class)]
#[CoversClass(ProcessManagerState::class)]
#[CoversClass(ProcessManagerStateEnum::class)]
final class ProcessManagerRepositoryTest extends TestCase
{
    public function test_handleStartsAndPersistsANewProcess(): void
    {
        $storage = new InMemoryProcessManagerStorage();
        $repository = new ProcessManagerRepository($storage);
        $aggregateId = EntityIdentifier::fromString('order-1');

        $processManager = $repository->handle(DummyOrderProcessManager::class, new DummyOrderCreated($aggregateId, null));

        $this->assertSame(ProcessManagerStateEnum::PROCESSING, $processManager->getState()->getStatus());

        $persisted = $storage->retrieve($aggregateId);
        $this->assertNotNull($persisted);
        $this->assertSame(ProcessManagerStateEnum::PROCESSING, $persisted->getStatus());
    }

    public function test_handleReloadsExistingProcessAcrossCalls(): void
    {
        $storage = new InMemoryProcessManagerStorage();
        $repository = new ProcessManagerRepository($storage);
        $aggregateId = EntityIdentifier::fromString('order-1');

        $repository->handle(DummyOrderProcessManager::class, new DummyOrderCreated($aggregateId, null));
        $processManager = $repository->handle(DummyOrderProcessManager::class, new DummyOrderShipped($aggregateId, null));

        $this->assertTrue($processManager->isComplete());

        $persisted = $storage->retrieve($aggregateId);
        $this->assertNotNull($persisted);
        $this->assertSame(ProcessManagerStateEnum::COMPLETED, $persisted->getStatus());
    }
    /**
     * Nothing ever cleaned up finished processes, so the state store grew
     * without bound. Opt-in rather than automatic: a consumer may want the
     * record for auditing, and forgetting it makes a redelivered event start a
     * fresh process instead of being recognised as done.
     */
    public function test_aCompletedProcessCanBeForgotten(): void
    {
        $storage = new InMemoryProcessManagerStorage();
        $repository = new ProcessManagerRepository($storage, forgetCompletedProcesses: true);
        $aggregateId = EntityIdentifier::fromString('order-forgettable');

        $repository->handle(DummyOrderProcessManager::class, new DummyOrderCreated($aggregateId, null));
        $this->assertNotNull($storage->retrieve($aggregateId), 'Still running, still stored.');

        $repository->handle(DummyOrderProcessManager::class, new DummyOrderShipped($aggregateId, null));

        $this->assertNull($storage->retrieve($aggregateId), 'A completed process has no state left to keep.');
    }

    public function test_aCompletedProcessIsKeptByDefault(): void
    {
        $storage = new InMemoryProcessManagerStorage();
        $repository = new ProcessManagerRepository($storage);
        $aggregateId = EntityIdentifier::fromString('order-remembered');

        $repository->handle(DummyOrderProcessManager::class, new DummyOrderCreated($aggregateId, null));
        $repository->handle(DummyOrderProcessManager::class, new DummyOrderShipped($aggregateId, null));

        $this->assertNotNull($storage->retrieve($aggregateId), 'Forgetting has to be asked for.');
    }

    /**
     * At-least-once delivery is the normal case for the transport this class
     * is built for, so the same event arriving twice has to be a no-op. It was
     * not: the finished state was loaded, handed the event again, and written
     * back — a store, a version bump and a fresh chance for `onEvent()` to act
     * on a process that had already ended.
     */
    public function test_aRedeliveredEventDoesNotRewriteAFinishedProcess(): void
    {
        $storage = new InMemoryProcessManagerStorage();
        $repository = new ProcessManagerRepository($storage);
        $aggregateId = EntityIdentifier::fromString('order-redelivered');
        $shipped = new DummyOrderShipped($aggregateId, null);

        $repository->handle(DummyOrderProcessManager::class, new DummyOrderCreated($aggregateId, null));
        $repository->handle(DummyOrderProcessManager::class, $shipped);

        $finished = $storage->retrieve($aggregateId);
        $this->assertNotNull($finished);

        $processManager = $repository->handle(DummyOrderProcessManager::class, $shipped);

        $stored = $storage->retrieve($aggregateId);
        $this->assertNotNull($stored);
        $this->assertSame(
            $finished->getVersion(),
            $stored->getVersion(),
            'A redelivery wrote to a process that had already finished.'
        );
        $this->assertSame(ProcessManagerStateEnum::COMPLETED, $processManager->getState()->getStatus());
        $this->assertTrue($processManager->isComplete(), 'The caller has to be told the process is done.');
    }

    /**
     * Everything after this point calls into the class statically, so a wrong
     * string used to produce an Error about an undefined method — nothing a
     * reader can act on. AggregateRepository gets the equivalent case right.
     */
    public function test_handleRejectsAClassThatIsNotAProcessManager(): void
    {
        $repository = new ProcessManagerRepository(new InMemoryProcessManagerStorage());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not an AbstractProcessManager');

        /** @phpstan-ignore-next-line deliberately the wrong class */
        $repository->handle(stdClass::class, new DummyOrderCreated(EntityIdentifier::fromString('order-x'), null));
    }

}
