<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Integration\Repository;

use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\ProjectorInterface;
use DomainFlow\EventSourcingCore\Provider\Integration\ProjectorDummyEvent;

final class InMemoryReplayProjector implements ProjectorInterface
{
    private array $counters = [];

    public static function getSubscribedTo(): array
    {
        return [ProjectorDummyEvent::class];
    }

    public function handle(
        DomainEventInterface $event
    ): void {
        if (!$this->supports($event::class)) {
            return;
        }

        /** @var ProjectorDummyEvent $event */
        $aggId = (string) $event->getAggregateId();
        $delta = $event->getDelta();

        $this->counters[$aggId] = ($this->counters[$aggId] ?? 0) + $delta;
    }

    public function reset(): void
    {
        $this->counters = [];
    }

    public function replay(
        DomainEventInterface ...$events
    ): void {
        foreach ($events as $event) {
            $this->handle($event);
        }
    }

    public function supports(
        string $eventClass
    ): bool {
        return in_array($eventClass, self::getSubscribedTo(), true);
    }

    public function getName(): string
    {
        return 'InMemoryReplayProjector';
    }

    public function getCounter(
        string $aggregateId
    ): ?int {
        return $this->counters[$aggregateId] ?? null;
    }

    public function rowExists(
        string $aggregateId
    ): bool {
        return array_key_exists($aggregateId, $this->counters);
    }

    public function all(): array
    {
        return $this->counters;
    }
}
