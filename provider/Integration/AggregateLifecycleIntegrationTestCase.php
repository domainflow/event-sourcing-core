<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Integration;

use DateTimeImmutable;
use DomainFlow\EventSourcing\Aggregate\AggregateRoot;
use DomainFlow\EventSourcing\Concurrency\MaxVersionStrategy;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Event\SourceEvent;
use DomainFlow\EventSourcing\Exception\ConcurrencyException;
use DomainFlow\EventSourcing\Facade\EventSourcingFacade;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventStorageInterface;
use DomainFlow\EventSourcing\Interface\SnapshotableAggregateInterface;
use DomainFlow\EventSourcing\Interface\SnapshotInterface;
use DomainFlow\EventSourcing\Interface\SnapshotStorageInterface;
use DomainFlow\EventSourcing\Snapshot\GenericSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * The aggregate lifecycle contract, driven exclusively through the public
 * EventSourcingFacade API.
 *
 * Every other case in provider/ constructs its events with an explicit
 * EventVersion::fromInt(n). That verifies the storage adapter, but leaves the
 * layer above it — the aggregate assigning its own versions — completely
 * untested, which is how the version-assignment defects this case now pins
 * down survived a fully green suite.
 *
 * The rule for this file: no test may ever pass a version by hand. Version
 * numbers are the aggregate's responsibility and are only ever asserted, never
 * supplied.
 */
abstract class AggregateLifecycleIntegrationTestCase extends TestCase
{
    abstract protected function getStorage(): EventStorageInterface;
    abstract protected function getSnapshotStorage(): SnapshotStorageInterface;

    /**
     * A facade over the adapter under test, with optimistic concurrency on.
     */
    private function facade(): EventSourcingFacade
    {
        $facade = new EventSourcingFacade($this->getStorage());
        $facade->enableConcurrencyCheck(new MaxVersionStrategy());

        return $facade;
    }

    /**
     * A facade with a snapshot store attached, for the paths where a snapshot
     * has to be visible to load() and delete().
     */
    private function facadeWithSnapshots(): EventSourcingFacade
    {
        $facade = new EventSourcingFacade($this->getStorage(), $this->getSnapshotStorage());
        $facade->enableConcurrencyCheck(new MaxVersionStrategy());

        return $facade;
    }

    /**
     * @return int[]
     */
    private function storedVersions(
        EntityIdentifierInterface $aggregateId
    ): array {
        return array_map(
            static fn (DomainEventInterface $event): int => $event->getVersion()->toInt(),
            $this->getStorage()->retrieveEvents($aggregateId)
        );
    }

    private function aggregateId(
        string $suffix
    ): EntityIdentifierInterface {
        return EntityIdentifier::fromString('lifecycle-' . $suffix);
    }

    public function test_aggregate_assigns_sequential_versions_within_one_batch(): void
    {
        $aggregateId = $this->aggregateId('batch');

        $account = new LifecycleAccount();
        $account->deposit($aggregateId, 100);
        $account->deposit($aggregateId, 50);
        $account->deposit($aggregateId, 25);

        $uncommitted = array_map(
            static fn (DomainEventInterface $event): int => $event->getVersion()->toInt(),
            $account->getUncommittedEvents()
        );

        $this->assertSame([1, 2, 3], $uncommitted, 'The aggregate must number its own events sequentially.');

        $this->facade()->persist($account);

        $this->assertSame([1, 2, 3], $this->storedVersions($aggregateId));
    }

    public function test_version_sequence_continues_after_a_reload(): void
    {
        $aggregateId = $this->aggregateId('continue');

        $account = new LifecycleAccount();
        $account->deposit($aggregateId, 100);
        $account->deposit($aggregateId, 50);
        $this->facade()->persist($account);

        $reloaded = $this->facade()->load(LifecycleAccount::class, $aggregateId);
        $this->assertInstanceOf(LifecycleAccount::class, $reloaded);
        $this->assertSame(
            2,
            $reloaded->getAggregateVersion()->toInt(),
            'A reloaded aggregate must carry the version of the last replayed event.'
        );

        $reloaded->deposit($aggregateId, 25);
        $this->assertSame(3, $reloaded->getUncommittedEvents()[0]->getVersion()->toInt());

        $this->facade()->persist($reloaded);

        $this->assertSame([1, 2, 3], $this->storedVersions($aggregateId));
    }

    public function test_state_survives_three_load_mutate_persist_round_trips(): void
    {
        $aggregateId = $this->aggregateId('roundtrips');

        $account = new LifecycleAccount();
        $account->deposit($aggregateId, 10);
        $this->facade()->persist($account);

        for ($round = 0; $round < 2; $round++) {
            $loaded = $this->facade()->load(LifecycleAccount::class, $aggregateId);
            $this->assertInstanceOf(LifecycleAccount::class, $loaded);
            $loaded->deposit($aggregateId, 10);
            $this->facade()->persist($loaded);
        }

        $final = $this->facade()->load(LifecycleAccount::class, $aggregateId);
        $this->assertInstanceOf(LifecycleAccount::class, $final);

        $this->assertSame(30, $final->getBalance());
        $this->assertSame([1, 2, 3], $this->storedVersions($aggregateId));
        $this->assertSame(3, $final->getAggregateVersion()->toInt());
    }

    public function test_a_stale_write_is_rejected_as_a_concurrency_conflict(): void
    {
        $aggregateId = $this->aggregateId('conflict');

        $account = new LifecycleAccount();
        $account->deposit($aggregateId, 100);
        $this->facade()->persist($account);

        $first = $this->facade()->load(LifecycleAccount::class, $aggregateId);
        $second = $this->facade()->load(LifecycleAccount::class, $aggregateId);
        $this->assertInstanceOf(LifecycleAccount::class, $first);
        $this->assertInstanceOf(LifecycleAccount::class, $second);

        $first->deposit($aggregateId, 10);
        $this->facade()->persist($first);

        $second->deposit($aggregateId, 20);

        $this->expectException(ConcurrencyException::class);
        $this->facade()->persist($second);
    }

    public function test_the_loser_of_a_conflict_leaves_the_stream_untouched(): void
    {
        $aggregateId = $this->aggregateId('conflict-stream');

        $account = new LifecycleAccount();
        $account->deposit($aggregateId, 100);
        $this->facade()->persist($account);

        $stale = $this->facade()->load(LifecycleAccount::class, $aggregateId);
        $this->assertInstanceOf(LifecycleAccount::class, $stale);

        $winner = $this->facade()->load(LifecycleAccount::class, $aggregateId);
        $this->assertInstanceOf(LifecycleAccount::class, $winner);
        $winner->deposit($aggregateId, 10);
        $this->facade()->persist($winner);

        $stale->deposit($aggregateId, 20);

        try {
            $this->facade()->persist($stale);
            $this->fail('Expected a ConcurrencyException for the stale write.');
        } catch (ConcurrencyException) {
            // expected
        }

        $this->assertSame([1, 2], $this->storedVersions($aggregateId));

        $final = $this->facade()->load(LifecycleAccount::class, $aggregateId);
        $this->assertInstanceOf(LifecycleAccount::class, $final);
        $this->assertSame(110, $final->getBalance());
    }

    /**
     * The load-from-snapshot path and the pure-replay path must agree. If they
     * disagree, one of them is wrong and neither the aggregate nor the store
     * can say which — the whole point of a snapshot is that it is an
     * optimisation, never a second source of truth.
     */
    public function test_loading_from_a_snapshot_matches_a_full_replay(): void
    {
        $aggregateId = $this->aggregateId('snapshot-parity');

        $account = new LifecycleAccount();
        $account->deposit($aggregateId, 100);
        $account->deposit($aggregateId, 50);
        $account->deposit($aggregateId, 25);
        $this->facade()->persist($account);

        $byReplay = $this->facade()->load(LifecycleAccount::class, $aggregateId);
        $this->assertInstanceOf(LifecycleAccount::class, $byReplay);

        // A snapshot covering only the first two events, so the third still has
        // to be replayed on top of it.
        $this->getSnapshotStorage()->storeSnapshot(new GenericSnapshot(
            $aggregateId,
            EventVersion::fromInt(2),
            ['balance' => 150],
            OccurredOn::now()
        ));

        $bySnapshot = $this->facadeWithSnapshots()->load(LifecycleAccount::class, $aggregateId);
        $this->assertInstanceOf(LifecycleAccount::class, $bySnapshot);

        $this->assertSame($byReplay->getBalance(), $bySnapshot->getBalance());
        $this->assertSame(175, $bySnapshot->getBalance());
        $this->assertSame(
            $byReplay->getAggregateVersion()->toInt(),
            $bySnapshot->getAggregateVersion()->toInt()
        );
    }

    /**
     * A snapshot whose version runs past the end of the stream is stale or was
     * written wrong. Trusting it would filter out events it does not actually
     * contain, so the repository has to fall back to replaying everything.
     */
    public function test_an_implausible_snapshot_is_ignored_rather_than_trusted(): void
    {
        $aggregateId = $this->aggregateId('snapshot-implausible');

        $account = new LifecycleAccount();
        $account->deposit($aggregateId, 100);
        $account->deposit($aggregateId, 50);
        $this->facade()->persist($account);

        $this->getSnapshotStorage()->storeSnapshot(new GenericSnapshot(
            $aggregateId,
            EventVersion::fromInt(99),
            ['balance' => 999999],
            OccurredOn::now()
        ));

        $loaded = $this->facadeWithSnapshots()->load(LifecycleAccount::class, $aggregateId);
        $this->assertInstanceOf(LifecycleAccount::class, $loaded);

        $this->assertSame(150, $loaded->getBalance(), 'The stream, not the bad snapshot, is the truth.');
        $this->assertSame(2, $loaded->getAggregateVersion()->toInt());
    }

    public function test_deleting_an_aggregate_also_removes_its_snapshot(): void
    {
        $aggregateId = $this->aggregateId('delete');

        $account = new LifecycleAccount();
        $account->deposit($aggregateId, 100);
        $account->deposit($aggregateId, 50);
        $this->facadeWithSnapshots()->persist($account);

        $this->getSnapshotStorage()->storeSnapshot(new GenericSnapshot(
            $aggregateId,
            EventVersion::fromInt(2),
            ['balance' => 150],
            OccurredOn::now()
        ));

        $fromSnapshot = $this->facadeWithSnapshots()->load(LifecycleAccount::class, $aggregateId);
        $this->assertInstanceOf(LifecycleAccount::class, $fromSnapshot);
        $this->assertSame(150, $fromSnapshot->getBalance(), 'Precondition: the snapshot is in use.');
        $this->assertSame(2, $fromSnapshot->getAggregateVersion()->toInt());

        $this->facadeWithSnapshots()->delete($aggregateId);

        $this->assertNull(
            $this->getSnapshotStorage()->retrieveSnapshot($aggregateId),
            'delete() must remove the snapshot, not just the events.'
        );
        $this->assertSame([], $this->storedVersions($aggregateId));

        $afterDelete = $this->facadeWithSnapshots()->load(LifecycleAccount::class, $aggregateId);
        $this->assertInstanceOf(LifecycleAccount::class, $afterDelete);
        $this->assertSame(0, $afterDelete->getBalance(), 'A deleted aggregate must not come back.');
    }

    /**
     * Two aggregate types reacting to the same event class must not share
     * handler resolution — one of them not declaring a handler must not break
     * the other, in either registration order.
     */
    public function test_two_aggregates_can_share_one_event_class(): void
    {
        $withHandler = $this->aggregateId('shared-with');
        $withoutHandler = $this->aggregateId('shared-without');

        $silent = new LifecycleSilentAccount();
        $silent->deposit($withoutHandler, 100);
        $this->facade()->persist($silent);

        $account = new LifecycleAccount();
        $account->deposit($withHandler, 100);

        $this->assertSame(
            100,
            $account->getBalance(),
            'A handler must still run after another aggregate class saw the same event class first.'
        );

        $this->facade()->persist($account);

        $reloaded = $this->facade()->load(LifecycleAccount::class, $withHandler);
        $this->assertInstanceOf(LifecycleAccount::class, $reloaded);
        $this->assertSame(100, $reloaded->getBalance());

        $reloadedSilent = $this->facade()->load(LifecycleSilentAccount::class, $withoutHandler);
        $this->assertInstanceOf(LifecycleSilentAccount::class, $reloadedSilent);
        $this->assertSame(1, $reloadedSilent->getAggregateVersion()->toInt());
    }
}

final class LifecycleDeposited extends SourceEvent
{
    public function __construct(
        EntityIdentifierInterface $aggregateId,
        private readonly int $amount,
        ?EntityIdentifierInterface $eventId = null,
        ?DateTimeImmutable $occurredOn = null,
        ?EventVersion $version = null
    ) {
        parent::__construct($aggregateId, $eventId, $occurredOn, $version);
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return parent::toArray() + ['amount' => $this->amount];
    }
}

final class LifecycleAccount extends AggregateRoot implements SnapshotableAggregateInterface
{
    private int $balance = 0;
    private ?EntityIdentifierInterface $aggregateId = null;

    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function getBalance(): int
    {
        return $this->balance;
    }

    public function deposit(
        EntityIdentifierInterface $aggregateId,
        int $amount
    ): void {
        $this->applyEvent(new LifecycleDeposited($aggregateId, $amount));
    }

    protected function applyLifecycleDeposited(
        LifecycleDeposited $event
    ): void {
        $this->aggregateId = $event->getAggregateId();
        $this->balance += $event->getAmount();
    }

    /**
     * Snapshots are written explicitly by the tests that need one, so that the
     * remaining cases exercise the pure-replay path.
     */
    public function shouldTakeSnapshot(): bool
    {
        return false;
    }

    public function getSnapshotClass(): string
    {
        return GenericSnapshot::class;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshotState(): array
    {
        return ['balance' => $this->balance];
    }

    public function getSnapshotVersion(): EventVersion
    {
        return $this->getAggregateVersion();
    }

    public function getAggregateId(): EntityIdentifierInterface
    {
        return $this->aggregateId ?? EntityIdentifier::fromString('unknown');
    }

    public function applySnapshot(
        SnapshotInterface $snapshot
    ): void {
        $state = $snapshot->getState();
        $this->balance = isset($state['balance']) && is_numeric($state['balance']) ? (int) $state['balance'] : 0;
    }
}

/**
 * Deliberately declares no handler for LifecycleDeposited: it records the
 * event but derives no state from it.
 */
final class LifecycleSilentAccount extends AggregateRoot
{
    public function __construct()
    {
    }

    protected static function newInstance(): static
    {
        return new static();
    }

    public function deposit(
        EntityIdentifierInterface $aggregateId,
        int $amount
    ): void {
        $this->applyEvent(new LifecycleDeposited($aggregateId, $amount));
    }
}
