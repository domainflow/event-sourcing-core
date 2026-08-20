<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\ProcessManager;

use DateTimeImmutable;
use DateTimeZone;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Exception\ProcessManagerConcurrencyException;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\ProcessManager\AbstractProcessManager;
use DomainFlow\EventSourcing\ProcessManager\InMemoryProcessManagerStorage;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerState;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerStateEnum;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerTimeoutResult;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerTimeoutRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ProcessManagerTimeoutRunner::class)]
#[CoversClass(ProcessManagerTimeoutResult::class)]
#[CoversClass(InMemoryProcessManagerStorage::class)]
#[CoversClass(AbstractProcessManager::class)]
#[CoversClass(ProcessManagerState::class)]
#[CoversClass(ProcessManagerStateEnum::class)]
#[CoversClass(EntityIdentifier::class)]
#[UsesClass(ProcessManagerConcurrencyException::class)]
final class ProcessManagerTimeoutRunnerTest extends TestCase
{
    private const string LATER = '2099-01-01 00:00:00.000000';

    private function overdue(
        InMemoryProcessManagerStorage $storage,
        string $processId,
        string $timeout = '2026-01-01 12:00:00.000000'
    ): ProcessManagerState {
        $state = new ProcessManagerState(EntityIdentifier::fromString($processId), ProcessManagerStateEnum::WAITING);
        $state->setTimeout(new DateTimeImmutable($timeout, new DateTimeZone('UTC')));
        $storage->store($state);

        return $state;
    }

    private function asOf(
        string $when = self::LATER
    ): DateTimeImmutable {
        return new DateTimeImmutable($when, new DateTimeZone('UTC'));
    }

    public function test_it_runs_the_timeout_hook_and_stores_what_the_saga_decided(): void
    {
        $storage = new InMemoryProcessManagerStorage();
        $this->overdue($storage, 'order-1');

        $runner = new ProcessManagerTimeoutRunner($storage, static fn (): string => GivingUpProcessManager::class);

        $result = $runner->run($this->asOf());

        $this->assertSame(1, $result->getFired());
        $this->assertFalse($result->isIdle());

        $stored = $storage->retrieve(EntityIdentifier::fromString('order-1'));
        $this->assertNotNull($stored);
        $this->assertSame(ProcessManagerStateEnum::FAILED, $stored->getStatus());
        $this->assertSame(['gave_up' => true], $stored->getData());
    }

    /**
     * The timeout is dropped as part of firing it. Without that the process is
     * still overdue the moment the pass ends, so the next pass hands it over
     * again — and so does every pass after that. The outbox has the same risk
     * when a poisoned entry is re-claimed forever.
     */
    public function test_a_fired_timeout_is_not_handed_over_a_second_time(): void
    {
        $storage = new InMemoryProcessManagerStorage();
        $this->overdue($storage, 'order-2');

        $runner = new ProcessManagerTimeoutRunner($storage, static fn (): string => CountingProcessManager::class);

        $this->assertSame(1, $runner->run($this->asOf())->getFired());
        $this->assertSame(0, $runner->run($this->asOf())->getFired(), 'The same timeout fired twice.');

        $this->assertSame([], $storage->findTimedOut($this->asOf(), 10));
    }

    /**
     * Cleared before the hook, not after: a saga that reschedules itself is the
     * normal way to express a retry, and clearing afterwards would silently
     * throw that new timeout away.
     */
    public function test_a_saga_that_reschedules_itself_keeps_the_new_timeout(): void
    {
        $storage = new InMemoryProcessManagerStorage();
        $this->overdue($storage, 'order-3');

        $runner = new ProcessManagerTimeoutRunner($storage, static fn (): string => ReschedulingProcessManager::class);
        $runner->run($this->asOf());

        $stored = $storage->retrieve(EntityIdentifier::fromString('order-3'));
        $this->assertNotNull($stored);
        $this->assertSame(
            ReschedulingProcessManager::NEXT_ATTEMPT,
            $stored->getTimeout()?->format('Y-m-d H:i:s.u'),
            'A rescheduled timeout was dropped along with the one that just fired.'
        );
    }

    /**
     * The store is keyed by process id alone and holds no type discriminator,
     * so the runner cannot know which saga a found state belongs to. Saying so
     * — by handing that decision to the caller — is better than guessing and
     * running one saga's hook against another saga's state.
     */
    public function test_a_state_the_caller_does_not_claim_is_left_alone(): void
    {
        $storage = new InMemoryProcessManagerStorage();
        $this->overdue($storage, 'not-mine');

        $runner = new ProcessManagerTimeoutRunner($storage, static fn (): ?string => null);

        $result = $runner->run($this->asOf());

        $this->assertSame(0, $result->getFired());
        $this->assertSame(1, $result->getSkipped());

        $stored = $storage->retrieve(EntityIdentifier::fromString('not-mine'));
        $this->assertNotNull($stored);
        $this->assertNotNull($stored->getTimeout(), 'A state nobody claimed must keep its timeout.');
        $this->assertSame(1, $stored->getVersion(), 'An unclaimed state must not be written at all.');
    }

    /**
     * Two timeout workers on one schedule finding the same overdue process is
     * the normal case. The version check decides it; the loser has to notice
     * and move on rather than crash the pass and leave the rest of the batch
     * unattended.
     */
    public function test_the_worker_that_loses_the_race_reports_it_instead_of_failing(): void
    {
        $storage = new ContendedProcessManagerStorage();
        $this->overdue($storage, 'order-4');

        $runner = new ProcessManagerTimeoutRunner($storage, static fn (): string => CountingProcessManager::class);

        $result = $runner->run($this->asOf());

        $this->assertSame(0, $result->getFired());
        $this->assertSame(1, $result->getContended());
        $this->assertFalse($result->isIdle());
    }

    /**
     * One saga throwing must not strand the rest of the batch, for the same
     * reason a failing subscriber does not stop the others.
     */
    public function test_a_hook_that_throws_does_not_strand_the_rest_of_the_batch(): void
    {
        $storage = new InMemoryProcessManagerStorage();
        $this->overdue($storage, 'order-explodes', '2026-01-01 09:00:00.000000');
        $this->overdue($storage, 'order-fine', '2026-01-01 10:00:00.000000');

        $runner = new ProcessManagerTimeoutRunner(
            $storage,
            static fn (ProcessManagerState $state): string => (string) $state->getProcessId() === 'order-explodes'
                ? ExplodingProcessManager::class
                : CountingProcessManager::class
        );

        $result = $runner->run($this->asOf());

        $this->assertSame(1, $result->getFired());
        $this->assertSame(1, $result->getFailed());

        $survivor = $storage->retrieve(EntityIdentifier::fromString('order-fine'));
        $this->assertNotNull($survivor);
        $this->assertNull($survivor->getTimeout(), 'The saga behind the failing one never ran.');
    }

    public function test_a_pass_with_nothing_due_is_idle(): void
    {
        $storage = new InMemoryProcessManagerStorage();
        $this->overdue($storage, 'order-5');

        $runner = new ProcessManagerTimeoutRunner($storage, static fn (): string => CountingProcessManager::class);

        $result = $runner->run($this->asOf('2026-01-01 11:59:59.999999'));

        $this->assertTrue($result->isIdle());
        $this->assertSame(0, $result->getFired());
        $this->assertSame(0, $result->getContended());
        $this->assertSame(0, $result->getSkipped());
        $this->assertSame(0, $result->getFailed());
    }

    public function test_a_pass_claims_no_more_than_its_batch_size(): void
    {
        $storage = new RecordingProcessManagerStorage();

        $runner = new ProcessManagerTimeoutRunner($storage, static fn (): string => CountingProcessManager::class, 7);
        $runner->run($this->asOf());

        $this->assertSame(7, $storage->requestedLimit, 'An unbounded poll is the footgun the limit exists to prevent.');
    }

    /**
     * Defaults to now, so a scheduled worker is a one-liner. Asserted against a
     * window rather than an instant, because the clock moves between the two
     * readings.
     */
    public function test_a_pass_without_an_explicit_cutoff_asks_about_now(): void
    {
        $storage = new RecordingProcessManagerStorage();
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        (new ProcessManagerTimeoutRunner($storage, static fn (): string => CountingProcessManager::class))->run();

        $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $this->assertNotNull($storage->requestedAsOf);
        $this->assertGreaterThanOrEqual($before, $storage->requestedAsOf);
        $this->assertLessThanOrEqual($after, $storage->requestedAsOf);
    }

    /**
     * A saga that has no use for timeouts must not have to say so. It never set
     * one, so it is never found — and if a consumer sets one anyway, the
     * default hook does nothing and the timeout is cleared rather than retried
     * forever.
     */
    public function test_a_saga_that_does_not_override_the_hook_is_unaffected(): void
    {
        $storage = new InMemoryProcessManagerStorage();
        $this->overdue($storage, 'order-6');

        $runner = new ProcessManagerTimeoutRunner($storage, static fn (): string => IndifferentProcessManager::class);

        $this->assertSame(1, $runner->run($this->asOf())->getFired());

        $stored = $storage->retrieve(EntityIdentifier::fromString('order-6'));
        $this->assertNotNull($stored);
        $this->assertSame(ProcessManagerStateEnum::WAITING, $stored->getStatus());
        $this->assertNull($stored->getTimeout());
    }
}

# dummy classes

/**
 * Hands out a state and then lets someone else write over it, which is what a
 * second timeout worker on the same schedule does.
 */
final class ContendedProcessManagerStorage extends InMemoryProcessManagerStorage
{
    public function findTimedOut(
        DateTimeImmutable $asOf,
        int $limit
    ): array {
        $found = parent::findTimedOut($asOf, $limit);

        foreach ($found as $state) {
            $competitor = $this->retrieve($state->getProcessId());

            if ($competitor !== null) {
                $competitor->clearTimeout();
                $competitor->setStatus(ProcessManagerStateEnum::COMPLETED);
                $this->store($competitor);
            }
        }

        return $found;
    }
}

final class RecordingProcessManagerStorage extends InMemoryProcessManagerStorage
{
    public ?int $requestedLimit = null;

    public ?DateTimeImmutable $requestedAsOf = null;

    public function findTimedOut(
        DateTimeImmutable $asOf,
        int $limit
    ): array {
        $this->requestedLimit = $limit;
        $this->requestedAsOf = $asOf;

        return parent::findTimedOut($asOf, $limit);
    }
}

abstract class TimeoutFixtureProcessManager extends AbstractProcessManager
{
    public static function getSubscribedTo(): array
    {
        return [];
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
        return [];
    }

    protected function onEvent(
        DomainEventInterface $event
    ): void {
    }
}

final class GivingUpProcessManager extends TimeoutFixtureProcessManager
{
    public function onTimeout(): void
    {
        $this->getState()->setData(['gave_up' => true]);
        $this->markFailed();
    }
}

final class CountingProcessManager extends TimeoutFixtureProcessManager
{
    public function onTimeout(): void
    {
        $state = $this->getState();
        $attempts = $state->getData()['attempts'] ?? 0;
        $state->setData(['attempts' => (is_int($attempts) ? $attempts : 0) + 1]);
    }
}

final class ReschedulingProcessManager extends TimeoutFixtureProcessManager
{
    public const string NEXT_ATTEMPT = '2026-01-01 13:00:00.000000';

    public function onTimeout(): void
    {
        $this->setTimeout(new DateTimeImmutable(self::NEXT_ATTEMPT, new DateTimeZone('UTC')));
    }
}

final class ExplodingProcessManager extends TimeoutFixtureProcessManager
{
    public function onTimeout(): void
    {
        throw new RuntimeException('the saga could not decide');
    }
}

final class IndifferentProcessManager extends TimeoutFixtureProcessManager
{
}
