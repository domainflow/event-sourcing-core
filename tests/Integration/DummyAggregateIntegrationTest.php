<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Integration;

use DomainFlow\EventSourcingCore\Provider\Integration\DummyAggregateIntegrationTestCase;
use DomainFlow\EventSourcingCore\Tests\Setup\InMemorySetup;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing()]
final class DummyAggregateIntegrationTest extends DummyAggregateIntegrationTestCase
{
    use InMemorySetup;
}
