<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Attribute;

use Attribute;

/**
 * Marks a property as personal data, to be encrypted with the key of the
 * subject the event names in its `#[DataSubjectId]` property.
 *
 * Declared next to the field rather than in a list somewhere else, for the
 * same reason as `#[EventName]`: a list drifts, and the field that quietly
 * stops being encrypted is the one nobody was looking at.
 *
 * **The property must be typed to hold a string.** Erasure replaces the value
 * with `RedactedValue::MARKER`, and a property that cannot hold one would
 * store fine and fail to read back years later — discovered by the erasure
 * itself, which is the worst possible moment. Anything that is not naturally
 * a string is the consumer's to encode before it reaches the event.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class PersonalData
{
    /**
     * @param string|null $key The payload key this property is stored under,
     *        when `toArray()` does not use the property's own name. Sealing
     *        matched on the property name alone and skipped anything it could
     *        not find under it, so an event storing `$email` as
     *        `'email_address'` wrote the address in the clear and said
     *        nothing. A mismatch is refused now, and this is how an
     *        event that means it says so.
     */
    public function __construct(
        public ?string $key = null
    ) {
    }
}
