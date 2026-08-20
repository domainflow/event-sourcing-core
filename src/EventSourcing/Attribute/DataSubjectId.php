<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Attribute;

use Attribute;

/**
 * Marks the property that says *whose* personal data an event carries.
 *
 * Erasure is keyed on this value: forgetting a subject's key is what makes
 * every event carrying that subject's data unreadable at once, rather than a
 * hunt through the stream for the events that mentioned them.
 *
 * The value itself is not encrypted — it is the handle erasure needs. If the
 * identifier is itself personal, use a pseudonymous one and keep the mapping
 * where it can be deleted.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class DataSubjectId
{
}
