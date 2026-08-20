<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Operation;

use DomainFlow\EventSourcing\Interface\SchemaManagerInterface;
use DomainFlow\EventSourcing\Operation\EnsureSchema;
use DomainFlow\EventSourcing\Operation\EnsureSchemaResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;

/**
 *  made setup callable; this makes it runnable from a deploy step, with
 * the dry run beside it rather than as a separate call an operator has to know
 * about.
 */
#[CoversClass(EnsureSchema::class)]
#[CoversClass(EnsureSchemaResult::class)]
final class EnsureSchemaTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function test_itAppliesTheSchemaAndSaysWhatThatMeant(): void
    {
        $manager = $this->createMock(SchemaManagerInterface::class);
        $manager->expects($this->once())->method('ensureSchema');
        $manager->method('describeSchema')->willReturn(['CREATE TABLE events', 'CREATE TABLE outbox']);

        $result = (new EnsureSchema($manager))();

        $this->assertTrue($result->wasApplied());
        $this->assertSame(['CREATE TABLE events', 'CREATE TABLE outbox'], $result->getDescription());
    }

    /**
     * The case a deploy step needs when the application user is not allowed to
     * run DDL: the list goes to whoever owns the database, and nothing is
     * touched here.
     *
     * @throws Exception
     */
    public function test_aDryRunDescribesWithoutTouchingAnything(): void
    {
        $manager = $this->createMock(SchemaManagerInterface::class);
        $manager->expects($this->never())->method('ensureSchema');
        $manager->method('describeSchema')->willReturn(['CREATE TABLE events']);

        $result = (new EnsureSchema($manager))(dryRun: true);

        $this->assertFalse($result->wasApplied());
        $this->assertSame(['CREATE TABLE events'], $result->getDescription());
    }

    /**
     * A backend with nothing to create says so, and an empty list is that
     * answer rather than a failure — Redis creates nothing, which is the one
     * response a consumer cannot tell apart from nobody having documented it.
     *
     * @throws Exception
     */
    public function test_aBackendWithNothingToCreateIsAnAnswer(): void
    {
        $manager = $this->createMock(SchemaManagerInterface::class);
        $manager->expects($this->once())->method('ensureSchema');
        $manager->method('describeSchema')->willReturn([]);

        $result = (new EnsureSchema($manager))();

        $this->assertTrue($result->wasApplied());
        $this->assertSame([], $result->getDescription());
    }
}
