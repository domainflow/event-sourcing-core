# DomainFlow EventSourcing Core

[![Tests](https://github.com/domainflow/event-sourcing-core/actions/workflows/tests.yml/badge.svg)](https://github.com/domainflow/event-sourcing-core/actions/workflows/tests.yml)
![Packagist Version](https://img.shields.io/packagist/v/domainflow/event-sourcing-core)
![PHP Version](https://img.shields.io/packagist/php-v/domainflow/event-sourcing-core)
![License](https://img.shields.io/github/license/domainflow/event-sourcing-core)
![PHPStan](https://img.shields.io/badge/PHPStan-Level%210-brightgreen.svg)

A persistence-agnostic PHP event sourcing library: aggregates, events, snapshots, optimistic concurrency, event upcasting, projections, and process managers. It defines the storage seams a concrete database technology implements — it never depends on a concrete database or framework itself.

## Requirements

- PHP 8.4+
- Sodium PHP extension `ext-sodium`
- DomainFlow Uuid Package `domainflow/uuid`

## Installation

```bash
composer require domainflow/event-sourcing-core
```

## Core concepts

- **Aggregate root** — `AggregateRoot`, a domain object whose state is derived by replaying its own event history. Extend it, implement `apply<EventShortName>()` handler methods and the `newInstance(): static` factory method.
- **Domain event** — `DomainEventInterface`, or extend the `SourceEvent` base class for the common boilerplate (aggregate ID, event ID, version, occurred-on timestamp).
- **Aggregate repository / facade** — `AggregateRepository` is the load/save orchestrator; `EventSourcingFacade` is the intended single public entry point most consumers use, wrapping the repository plus optional concurrency checking, snapshotting, and event dispatch.
- **Crypto-shredding** — `#[PersonalData]` on a field, `#[DataSubjectId]` on the one that says whose it is, and erasure is destroying that subject's key: the event stays exactly as written and stops being readable. It is a decorator around the entry factory, so no storage adapter is involved. After erasure the field reads `RedactedValue::MARKER` rather than null, so a projector can tell "erased" from "never set".
- **Operational commands** — `Operation\DrainOutbox`, `Operation\RebuildProjection` and `Operation\EnsureSchema`: the three things that have to be *run* in production, as plain invokables that return a result and log nothing. No console dependency here, so bind them to whatever CLI you have. `DrainOutbox` is the relay loop with the parts that are easy to get wrong — back-off on an idle pass and none on a busy one, `maxPasses`/`maxSeconds` so the same object serves cron and a daemon, and a `stop()` flag read between passes so a `SIGTERM` never drops the entries the current pass claimed.
- **Storage interfaces** — `EventStorageInterface`, `SnapshotStorageInterface`, `SnapshotHistoryStorageInterface`, `ProcessManagerStorageInterface`. Implement these against a concrete database to build a new storage adapter.
- **Process manager** — `AbstractProcessManager` for event-driven sagas: implements `ProcessManagerInterface` and `EventSubscriberInterface`, so a single instance can register directly with `EventDispatcher`. Saga timeouts fire from `ProcessManagerTimeoutRunner`, which you schedule the same way you schedule `OutboxRelay` — a timeout exists for the case where no event is arriving, so nothing else is going to look at the process again.

## Usage

```php
use DomainFlow\EventSourcing\Facade\EventSourcingFacade;
use DomainFlow\EventSourcing\Storage\InMemoryEventStorage;

$facade = new EventSourcingFacade(new InMemoryEventStorage());

$order = new Order();
$order->create($orderId, 'customer-1');
$facade->persist($order);

$reloaded = $facade->load(Order::class, $orderId);
```

## Building your own storage adapter

`provider/Unit/` and `provider/Integration/` ship as production code (not dev-only) specifically so an adapter package can `composer require` this package and reuse these abstract PHPUnit test cases to prove its concrete storage classes satisfy the same contract this library's own `InMemory*` reference adapters do.

## Development

```bash
# Run inside package
composer install

# Quality suit (lint + static analysis + full test suite (100% coverage required) + audit)
composer quality 

# Or just tests
composer test-all
```


## License

MIT — see [LICENSE](LICENSE).
