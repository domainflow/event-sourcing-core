<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Attribute;

use Attribute;

/**
 * The name an event is stored under.
 *
 * Declared next to the class rather than in a list somewhere else, because a
 * list drifts: the one thing that must never happen is a class whose stored
 * name silently changes, and a registration a hundred files away is exactly
 * how that happens.
 *
 * The value is part of the stored data format. Once events have been written
 * under a name, that name is permanent — the class may be renamed, moved or
 * split freely, which is the whole point, but the name may not.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class EventName
{
    public function __construct(
        public string $name
    ) {
    }
}
