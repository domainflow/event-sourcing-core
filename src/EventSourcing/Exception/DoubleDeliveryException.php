<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Exception;

/**
 * Two delivery paths were configured at once, so every event would go out
 * twice.
 *
 * Named after the consequence rather than after the setting, because the
 * consequence is the part that is hard to see: nothing fails at the writer,
 * and the duplicate arrives at the consumer.
 */
class DoubleDeliveryException extends EventSourcingException
{
}
