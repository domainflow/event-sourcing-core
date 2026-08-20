<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Operation;

/**
 * What a schema run did, or would have done.
 *
 * The description comes back either way, so a deploy step can print the same
 * list whether it applied it or was only asked what it would apply.
 */
final readonly class EnsureSchemaResult
{
    /**
     * @param bool $applied
     * @param list<string> $description One human-readable line per object.
     *        Empty is an answer, not a failure: a backend with nothing to
     *        create says so, which is the one response a consumer cannot tell
     *        apart from nobody having documented it.
     */
    public function __construct(
        private bool $applied,
        private array $description
    ) {
    }

    public function wasApplied(): bool
    {
        return $this->applied;
    }

    /**
     * @return list<string>
     */
    public function getDescription(): array
    {
        return $this->description;
    }
}
