<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Unit;

use DateTimeImmutable;
use DateTimeZone;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Exception\ProcessManagerConcurrencyException;
use DomainFlow\EventSourcing\Interface\ProcessManagerStorageInterface;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerState;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerStateEnum;
use PHPUnit\Framework\TestCase;

abstract class AbstractProcessManagerStorageTestCase extends TestCase
{
    abstract protected function getProcessManagerStorage(): ProcessManagerStorageInterface;

    public function test_retrieve_returns_null_when_nothing_stored(): void
    {
        $storage = $this->getProcessManagerStorage();

        $this->assertNull($storage->retrieve(EntityIdentifier::fromString('missing-process')));
    }

    public function test_store_and_retrieve_roundtrips_state(): void
    {
        $storage = $this->getProcessManagerStorage();
        $processId = EntityIdentifier::fromString('process-123');

        $state = new ProcessManagerState($processId, ProcessManagerStateEnum::PROCESSING);
        $state->setData(['foo' => 'bar']);

        $storage->store($state);

        $retrieved = $storage->retrieve($processId);

        $this->assertNotNull($retrieved);
        $this->assertSame((string) $processId, (string) $retrieved->getProcessId());
        $this->assertSame(ProcessManagerStateEnum::PROCESSING, $retrieved->getStatus());
        $this->assertSame(['foo' => 'bar'], $retrieved->getData());
    }

    /**
     * The normal path: load, mutate, store. The version travels with the state
     * that was loaded, so this write is the expected next one.
     */
    public function test_storing_a_state_that_was_loaded_updates_it(): void
    {
        $storage = $this->getProcessManagerStorage();
        $processId = EntityIdentifier::fromString('process-456');

        $storage->store(new ProcessManagerState($processId, ProcessManagerStateEnum::WAITING));

        $loaded = $storage->retrieve($processId);
        $this->assertNotNull($loaded);

        $loaded->setStatus(ProcessManagerStateEnum::COMPLETED);
        $storage->store($loaded);

        $retrieved = $storage->retrieve($processId);

        $this->assertNotNull($retrieved);
        $this->assertSame(ProcessManagerStateEnum::COMPLETED, $retrieved->getStatus());
    }

    /**
     * The defect this guard exists for. `ProcessManagerRepository` is built for
     * reload-per-event consumers — a queue worker pool — and there two workers
     * routinely handle events for the same saga at once. An unconditional
     * overwrite loses one update per overlap, and a saga can then sit in
     * WAITING forever, which is the worst kind of bug: nothing failed.
     */
    public function test_a_write_against_a_stale_version_is_rejected_and_loses_nothing(): void
    {
        $storage = $this->getProcessManagerStorage();
        $processId = EntityIdentifier::fromString('process-contended');

        $storage->store(new ProcessManagerState($processId, ProcessManagerStateEnum::WAITING));

        // Two workers load the same state.
        $first = $storage->retrieve($processId);
        $second = $storage->retrieve($processId);
        $this->assertNotNull($first);
        $this->assertNotNull($second);

        $first->setStatus(ProcessManagerStateEnum::PROCESSING);
        $storage->store($first);

        $second->setStatus(ProcessManagerStateEnum::FAILED);

        $rejected = false;

        try {
            $storage->store($second);
        } catch (ProcessManagerConcurrencyException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'The second worker was writing over an update it never saw.');

        $retrieved = $storage->retrieve($processId);
        $this->assertNotNull($retrieved);
        $this->assertSame(
            ProcessManagerStateEnum::PROCESSING,
            $retrieved->getStatus(),
            'The winner\'s update has to survive.'
        );
    }

    /**
     * A state that has never been stored is at version 0, so "insert only if
     * absent" is the same comparison as every other write rather than a special
     * case — and a second worker inserting the same process concurrently is
     * caught by it.
     */
    public function test_inserting_a_process_that_already_exists_is_rejected(): void
    {
        $storage = $this->getProcessManagerStorage();
        $processId = EntityIdentifier::fromString('process-duplicate');

        $storage->store(new ProcessManagerState($processId, ProcessManagerStateEnum::WAITING));

        $this->expectException(ProcessManagerConcurrencyException::class);

        $storage->store(new ProcessManagerState($processId, ProcessManagerStateEnum::PROCESSING));
    }

    public function test_a_stored_state_carries_the_version_it_was_written_at(): void
    {
        $storage = $this->getProcessManagerStorage();
        $processId = EntityIdentifier::fromString('process-versioned');

        $state = new ProcessManagerState($processId, ProcessManagerStateEnum::WAITING);
        $this->assertSame(0, $state->getVersion(), 'Never stored means version zero.');

        $storage->store($state);
        $this->assertSame(1, $state->getVersion(), 'A long-lived process manager must be able to store again without reloading.');

        $storage->store($state);
        $this->assertSame(2, $state->getVersion());
    }

    /**
     * A cleared timeout must not linger. Every adapter rewrites the stored
     * state rather than merging into it, which is what makes this possible —
     * and until `clearTimeout()` existed there was no way to ask for it, so the
     * behaviour was never actually exercised.
     */
    public function test_clearing_a_timeout_removes_it_from_the_stored_state(): void
    {
        $storage = $this->getProcessManagerStorage();
        $processId = EntityIdentifier::fromString('process-timeout-dropped');

        $state = new ProcessManagerState($processId, ProcessManagerStateEnum::WAITING);
        $state->setTimeout(new DateTimeImmutable('2026-01-01 12:00:00.000000'));
        $storage->store($state);

        $loaded = $storage->retrieve($processId);
        $this->assertNotNull($loaded);
        $this->assertNotNull($loaded->getTimeout(), 'Precondition: a timeout was stored.');

        $loaded->clearTimeout();
        $storage->store($loaded);

        $retrieved = $storage->retrieve($processId);
        $this->assertNotNull($retrieved);
        $this->assertNull($retrieved->getTimeout(), 'A timeout that was dropped must not come back.');
    }

    /**
     * The whole point of a saga timeout is the case where nothing is arriving:
     * no event, no worker holding the process id, nothing to trigger a read.
     * `retrieve()` cannot express that question — it needs the id you are
     * trying to discover — so a timeout was a stored field with no reader.
     */
    public function test_find_timed_out_returns_a_process_whose_timeout_has_passed(): void
    {
        $storage = $this->getProcessManagerStorage();
        $processId = EntityIdentifier::fromString('process-overdue');

        $state = new ProcessManagerState($processId, ProcessManagerStateEnum::WAITING);
        $state->setTimeout(new DateTimeImmutable('2026-01-01 12:00:00.000000', new DateTimeZone('UTC')));
        $storage->store($state);

        $found = $storage->findTimedOut(new DateTimeImmutable('2026-01-01 12:00:01.000000', new DateTimeZone('UTC')), 10);

        $this->assertCount(1, $found);
        $this->assertSame((string) $processId, (string) $found[0]->getProcessId());
    }

    /**
     * The boundary is inclusive: a timeout is due at the instant it names, not
     * a microsecond later.
     */
    public function test_find_timed_out_includes_a_timeout_falling_exactly_on_the_cutoff(): void
    {
        $storage = $this->getProcessManagerStorage();
        $processId = EntityIdentifier::fromString('process-exactly-due');
        $due = new DateTimeImmutable('2026-01-01 12:00:00.000000', new DateTimeZone('UTC'));

        $state = new ProcessManagerState($processId, ProcessManagerStateEnum::WAITING);
        $state->setTimeout($due);
        $storage->store($state);

        $this->assertCount(1, $storage->findTimedOut($due, 10));
    }

    public function test_find_timed_out_ignores_a_timeout_that_has_not_arrived_yet(): void
    {
        $storage = $this->getProcessManagerStorage();

        $state = new ProcessManagerState(EntityIdentifier::fromString('process-later'), ProcessManagerStateEnum::WAITING);
        $state->setTimeout(new DateTimeImmutable('2026-01-01 12:00:00.000000', new DateTimeZone('UTC')));
        $storage->store($state);

        $this->assertSame([], $storage->findTimedOut(new DateTimeImmutable('2026-01-01 11:59:59.999999', new DateTimeZone('UTC')), 10));
    }

    public function test_find_timed_out_ignores_a_process_that_never_set_a_timeout(): void
    {
        $storage = $this->getProcessManagerStorage();

        $storage->store(new ProcessManagerState(EntityIdentifier::fromString('process-untimed'), ProcessManagerStateEnum::WAITING));

        $this->assertSame([], $storage->findTimedOut(new DateTimeImmutable('2099-01-01 00:00:00.000000', new DateTimeZone('UTC')), 10));
    }

    /**
     * A finished saga keeps its row for auditing, and its timeout with it.
     * Returning it would hand a timeout worker a process there is nothing left
     * to do to — every pass, forever, because a completed process is not going
     * to clear anything.
     */
    public function test_find_timed_out_ignores_processes_that_have_already_finished(): void
    {
        $storage = $this->getProcessManagerStorage();

        foreach ([ProcessManagerStateEnum::COMPLETED, ProcessManagerStateEnum::FAILED] as $status) {
            $state = new ProcessManagerState(EntityIdentifier::fromString('process-done-' . $status->value), $status);
            $state->setTimeout(new DateTimeImmutable('2026-01-01 12:00:00.000000', new DateTimeZone('UTC')));
            $storage->store($state);
        }

        $this->assertSame([], $storage->findTimedOut(new DateTimeImmutable('2099-01-01 00:00:00.000000', new DateTimeZone('UTC')), 10));
    }

    /**
     * `clearTimeout()` is what a saga calls once it has handled its timeout. If
     * the cleared value stayed findable, the process would be handed back on
     * the very next pass and the hook would run again on every pass after that
     * — the same failure shape as a repeatedly claimed outbox entry, in a
     * different queue.
     */
    public function test_find_timed_out_ignores_a_timeout_that_was_cleared(): void
    {
        $storage = $this->getProcessManagerStorage();
        $processId = EntityIdentifier::fromString('process-timeout-handled');

        $state = new ProcessManagerState($processId, ProcessManagerStateEnum::WAITING);
        $state->setTimeout(new DateTimeImmutable('2026-01-01 12:00:00.000000', new DateTimeZone('UTC')));
        $storage->store($state);

        $loaded = $storage->retrieve($processId);
        $this->assertNotNull($loaded);
        $loaded->clearTimeout();
        $storage->store($loaded);

        $this->assertSame([], $storage->findTimedOut(new DateTimeImmutable('2099-01-01 00:00:00.000000', new DateTimeZone('UTC')), 10));
    }

    /**
     * Oldest first, and that is a promise rather than an accident of the query
     * plan. With a limit and no ordering, a store that keeps answering with the
     * same page starves everything behind it — and the process that has been
     * waiting longest is exactly the one that must not be starved.
     */
    public function test_find_timed_out_returns_the_longest_overdue_first_and_honours_the_limit(): void
    {
        $storage = $this->getProcessManagerStorage();

        foreach (['third' => '11:00:00', 'first' => '09:00:00', 'second' => '10:00:00'] as $name => $time) {
            $state = new ProcessManagerState(EntityIdentifier::fromString('process-' . $name), ProcessManagerStateEnum::WAITING);
            $state->setTimeout(new DateTimeImmutable('2026-01-01 ' . $time . '.000000', new DateTimeZone('UTC')));
            $storage->store($state);
        }

        $asOf = new DateTimeImmutable('2026-01-01 12:00:00.000000', new DateTimeZone('UTC'));

        $found = $storage->findTimedOut($asOf, 2);

        $this->assertCount(2, $found, 'The limit is what keeps a worker\'s poll bounded.');
        $this->assertSame('process-first', (string) $found[0]->getProcessId());
        $this->assertSame('process-second', (string) $found[1]->getProcessId());
    }

    /**
     * A limit of zero asks for nothing, and every backend has to agree on that
     * for a caller to be able to compute one. It is not a hypothetical: in
     * MongoDB a `limit` of 0 means *no limit*, so the reading that looks like
     * "give me nothing" is the one that returns the entire overdue set.
     */
    public function test_find_timed_out_returns_nothing_when_asked_for_nothing(): void
    {
        $storage = $this->getProcessManagerStorage();

        $state = new ProcessManagerState(EntityIdentifier::fromString('process-unasked'), ProcessManagerStateEnum::WAITING);
        $state->setTimeout(new DateTimeImmutable('2026-01-01 12:00:00.000000', new DateTimeZone('UTC')));
        $storage->store($state);

        $this->assertSame([], $storage->findTimedOut(new DateTimeImmutable('2099-01-01 00:00:00.000000', new DateTimeZone('UTC')), 0));
    }

    /**
     * A found state is a state, not a stub: the worker is about to run the
     * saga's timeout hook against it and store the result, so it needs the data
     * and the version it was loaded at.
     */
    public function test_a_timed_out_process_comes_back_whole(): void
    {
        $storage = $this->getProcessManagerStorage();
        $processId = EntityIdentifier::fromString('process-whole');

        $state = new ProcessManagerState($processId, ProcessManagerStateEnum::PROCESSING);
        $state->setData(['attempts' => 2]);
        $state->setTimeout(new DateTimeImmutable('2026-01-01 12:00:00.000000', new DateTimeZone('UTC')));
        $storage->store($state);

        $found = $storage->findTimedOut(new DateTimeImmutable('2099-01-01 00:00:00.000000', new DateTimeZone('UTC')), 10);

        $this->assertCount(1, $found);
        $this->assertSame(ProcessManagerStateEnum::PROCESSING, $found[0]->getStatus());
        $this->assertSame(['attempts' => 2], $found[0]->getData());
        $this->assertSame(1, $found[0]->getVersion(), 'Without the version, the worker cannot store what it just decided.');
        $this->assertNotNull($found[0]->getTimeout());
    }

    /**
     * Two timeout workers on one schedule will find the same overdue process —
     * that is the normal case, not the exotic one. The version check that
     * The concurrency check on `store()` decides it, so exactly one of them gets
     * to advance the saga and the other is told to move on.
     */
    public function test_two_workers_racing_on_the_same_timed_out_process_produce_one_winner(): void
    {
        $storage = $this->getProcessManagerStorage();
        $processId = EntityIdentifier::fromString('process-contended-timeout');

        $state = new ProcessManagerState($processId, ProcessManagerStateEnum::WAITING);
        $state->setTimeout(new DateTimeImmutable('2026-01-01 12:00:00.000000', new DateTimeZone('UTC')));
        $storage->store($state);

        $asOf = new DateTimeImmutable('2099-01-01 00:00:00.000000', new DateTimeZone('UTC'));

        $first = $storage->findTimedOut($asOf, 10);
        $second = $storage->findTimedOut($asOf, 10);
        $this->assertCount(1, $first);
        $this->assertCount(1, $second);

        $first[0]->clearTimeout();
        $first[0]->setStatus(ProcessManagerStateEnum::COMPLETED);
        $storage->store($first[0]);

        $second[0]->clearTimeout();
        $second[0]->setStatus(ProcessManagerStateEnum::FAILED);

        $rejected = false;

        try {
            $storage->store($second[0]);
        } catch (ProcessManagerConcurrencyException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'Both workers fired the same saga.');

        $retrieved = $storage->retrieve($processId);
        $this->assertNotNull($retrieved);
        $this->assertSame(ProcessManagerStateEnum::COMPLETED, $retrieved->getStatus());
    }

    /**
     * The stored format carries no offset, so the instant has to be pinned on
     * the way in — otherwise two services in one cluster with different
     * `date.timezone` settings write values into the same column that cannot be
     * compared, and a timeout fires hours early or hours late depending on
     * which host wrote it. A timeout is read by a comparison, which makes
     * timezone consistency essential.
     */
    public function test_find_timed_out_compares_instants_rather_than_wall_clock_readings(): void
    {
        $storage = $this->getProcessManagerStorage();

        // 09:00 in Tokyo is 00:00 UTC — already past by the cutoff below.
        $state = new ProcessManagerState(EntityIdentifier::fromString('process-tokyo'), ProcessManagerStateEnum::WAITING);
        $state->setTimeout(new DateTimeImmutable('2026-01-01 09:00:00.000000', new DateTimeZone('Asia/Tokyo')));
        $storage->store($state);

        $found = $storage->findTimedOut(new DateTimeImmutable('2026-01-01 01:00:00.000000', new DateTimeZone('UTC')), 10);

        $this->assertCount(1, $found, 'A timeout written in another zone was stored as a wall-clock reading.');
    }

    public function test_delete_removes_stored_state(): void
    {
        $storage = $this->getProcessManagerStorage();
        $processId = EntityIdentifier::fromString('process-789');

        $storage->store(new ProcessManagerState($processId));
        $this->assertNotNull($storage->retrieve($processId));

        $storage->delete($processId);

        $this->assertNull($storage->retrieve($processId));
    }
}
