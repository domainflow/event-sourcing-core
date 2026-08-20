<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Exception;

use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;

/**
 * A process manager's state moved on while this worker was handling an event.
 *
 * Separate from ConcurrencyException, which is about an aggregate's event
 * stream: the recovery is different. An aggregate conflict means reload and
 * retry the command; a process-manager conflict means the event has to be
 * handled again against the newer state, and the caller usually wants the
 * queue to redeliver rather than to loop in place.
 */
final class ProcessManagerConcurrencyException extends EventSourcingException
{
    public static function versionMoved(
        EntityIdentifierInterface $processId,
        int $expected,
        int $found
    ): self {
        return new self(sprintf(
            'Process manager %s was at version %d when this state was loaded and is now at version %d. '
            . 'Another worker handled an event for the same process; handle this event again against the '
            . 'newer state rather than overwriting it.',
            (string) $processId,
            $expected,
            $found
        ));
    }
}
