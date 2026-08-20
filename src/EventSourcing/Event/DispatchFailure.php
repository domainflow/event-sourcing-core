<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Event;

use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventSubscriberInterface;
use Throwable;

/**
 * One subscriber's failure to handle one event.
 *
 * Carries the subscriber and the event rather than only the throwable,
 * because "which subscriber, on which event" is what a caller needs in order
 * to retry it or to route it to a dead-letter queue. A stack trace answers
 * that for a human reading a log, not for code.
 */
final readonly class DispatchFailure
{
    public function __construct(
        private EventSubscriberInterface $subscriber,
        private DomainEventInterface $event,
        private Throwable $failure
    ) {
    }

    public function getSubscriber(): EventSubscriberInterface
    {
        return $this->subscriber;
    }

    public function getEvent(): DomainEventInterface
    {
        return $this->event;
    }

    public function getFailure(): Throwable
    {
        return $this->failure;
    }
}
