<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Exception;

use DomainFlow\EventSourcing\Event\DispatchFailure;

/**
 * Raised after every subscriber has been given its turn, when at least one of
 * them failed.
 *
 * Deliberately raised *after* rather than instead of continuing: subscribers
 * are independent readers of the same event, and letting the first failure
 * cancel the rest turns one broken projector into a silent outage of every
 * other one.
 *
 * The events themselves are already committed by the time this is thrown. It
 * reports a delivery failure, never a write failure.
 */
final class SubscriberDispatchException extends EventSourcingException
{
    /**
     * @param non-empty-list<DispatchFailure> $failures
     */
    public function __construct(
        private readonly array $failures
    ) {
        parent::__construct(sprintf(
            '%d of the dispatched events could not be handled: %s. The events are stored; only their '
            . 'delivery failed.',
            count($failures),
            implode(', ', array_map(
                static fn (DispatchFailure $failure): string => sprintf(
                    '%s on %s (%s)',
                    $failure->getSubscriber()::class,
                    $failure->getEvent()::class,
                    $failure->getFailure()->getMessage()
                ),
                $failures
            ))
        ), 0, $failures[0]->getFailure());
    }

    /**
     * Every failure, not just the first: a caller retrying or dead-lettering
     * needs all of them.
     *
     * @return non-empty-list<DispatchFailure>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }
}
