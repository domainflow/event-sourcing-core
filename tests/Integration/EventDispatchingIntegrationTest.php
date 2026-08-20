<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Integration;

use DomainFlow\EventSourcingCore\Provider\Integration\EventDispatchingIntegrationTestCase;
use DomainFlow\EventSourcingCore\Tests\Setup\InMemorySetup;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing()]
final class EventDispatchingIntegrationTest extends EventDispatchingIntegrationTestCase
{
    use InMemorySetup;
}
