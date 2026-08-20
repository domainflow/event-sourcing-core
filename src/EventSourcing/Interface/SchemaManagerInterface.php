<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

/**
 * Getting a store ready, as something a consumer can call.
 *
 * Setup used to be a different shape in every adapter and undocumented in one:
 * MySQL wanted five `.sql` files applied by hand, MongoDB created its indexes
 * on the first write — invisibly, in production, and needing index privileges
 * at request time forever — and Redis needed nothing but said so nowhere.
 * Three answers to one question, and the MongoDB one carried an operational
 * cost the others did not.
 *
 * **This is not a migration framework.** No version table, no up/down, no
 * history. Those belong to whatever the consumer already runs, and a
 * second-rate copy of Doctrine Migrations or Phinx living in an event-sourcing
 * package would serve nobody. The seam exists so setup is *callable* and
 * testable, not so it is *managed*.
 */
interface SchemaManagerInterface
{
    /**
     * Bring the store to the shape this package expects.
     *
     * **Idempotent.** Running it twice is not an error and not a second set of
     * anything — a consumer will call it from a deploy step that reruns, and an
     * operator will call it by hand to be sure.
     *
     * @return void
     */
    public function ensureSchema(): void;

    /**
     * Remove everything `ensureSchema()` creates.
     *
     * For test teardown and for tearing an environment down on purpose. It
     * destroys data, which is why it is a separate call and not a flag.
     *
     * @return void
     */
    public function dropSchema(): void;

    /**
     * What `ensureSchema()` would do, without doing it.
     *
     * One human-readable line per object, so a consumer can put a dry run in
     * front of a deploy step and an operator can hand the list to whoever owns
     * the database when the application user is not allowed to run it.
     *
     * @return list<string>
     */
    public function describeSchema(): array;
}
