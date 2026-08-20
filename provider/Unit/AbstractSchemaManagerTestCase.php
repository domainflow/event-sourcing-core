<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Provider\Unit;

use DomainFlow\EventSourcing\Interface\SchemaManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The contract every adapter's schema manager answers.
 *
 * Idempotency is the property here that is easy to claim and easy to get
 * wrong — it only shows up on the second call, which is the one nobody makes
 * by hand.
 */
abstract class AbstractSchemaManagerTestCase extends TestCase
{
    abstract protected function getSchemaManager(): SchemaManagerInterface;

    /**
     * Writes one event through a storage built against the same store, so
     * "the schema is in place" means the thing a consumer actually cares
     * about rather than the existence of a name.
     */
    abstract protected function writeAnEvent(): void;

    /**
     * Whether the store currently holds what `ensureSchema()` creates.
     */
    abstract protected function schemaExists(): bool;

    public function test_ensure_schema_creates_what_a_write_needs(): void
    {
        $manager = $this->getSchemaManager();

        $manager->dropSchema();
        $manager->ensureSchema();

        $this->writeAnEvent();

        $this->assertTrue($this->schemaExists());
    }

    /**
     * The call a deploy step reruns and an operator repeats by hand. It must
     * be dull the second time — not an error, and not a second set of
     * anything.
     */
    public function test_ensure_schema_twice_is_not_an_error(): void
    {
        $manager = $this->getSchemaManager();

        $manager->dropSchema();
        $manager->ensureSchema();
        $manager->ensureSchema();

        $this->writeAnEvent();

        $this->assertTrue($this->schemaExists());
    }

    /**
     * Against an existing schema too, not only a fresh one: the second call is
     * the one a redeploy makes, and by then there is data.
     */
    public function test_ensure_schema_keeps_what_is_already_there(): void
    {
        $manager = $this->getSchemaManager();

        $manager->dropSchema();
        $manager->ensureSchema();
        $this->writeAnEvent();

        $manager->ensureSchema();

        $this->assertTrue($this->schemaExists(), 'A rerun of setup wiped the store it was meant to leave alone.');
    }

    public function test_drop_schema_removes_it(): void
    {
        $manager = $this->getSchemaManager();

        $manager->ensureSchema();
        $manager->dropSchema();

        $this->assertFalse($this->schemaExists());
    }

    /**
     * Dropping what is not there is the teardown half of the same promise:
     * a test that failed before it created anything still runs its teardown.
     */
    public function test_drop_schema_twice_is_not_an_error(): void
    {
        $manager = $this->getSchemaManager();

        $manager->dropSchema();
        $manager->dropSchema();

        $this->assertFalse($this->schemaExists());
    }

    /**
     * A dry run has to be readable by whoever owns the database, which is not
     * always the person deploying — that is the case it exists for.
     */
    public function test_describe_schema_lists_what_would_be_done_without_doing_it(): void
    {
        $manager = $this->getSchemaManager();

        $manager->dropSchema();

        $description = $manager->describeSchema();

        $this->assertNotSame([], $description);
        $this->assertSame(array_values($description), $description, 'A description is a list, not a map.');

        foreach ($description as $line) {
            $this->assertNotSame('', trim($line));
        }

        $this->assertFalse($this->schemaExists(), 'Describing the schema created it.');
    }
}
