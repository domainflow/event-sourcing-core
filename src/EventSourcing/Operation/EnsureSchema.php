<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Operation;

use DomainFlow\EventSourcing\Interface\SchemaManagerInterface;

/**
 * Creating the store's schema, as something a deploy step runs.
 */
final readonly class EnsureSchema
{
    public function __construct(
        private SchemaManagerInterface $schema
    ) {
    }

    /**
     * @param bool $dryRun Describe what would be done and do none of it.
     * @return EnsureSchemaResult
     */
    public function __invoke(
        bool $dryRun = false
    ): EnsureSchemaResult {
        $description = $this->schema->describeSchema();

        if ($dryRun) {
            return new EnsureSchemaResult(false, $description);
        }

        $this->schema->ensureSchema();

        return new EnsureSchemaResult(true, $description);
    }
}
