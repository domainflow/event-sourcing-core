<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Integration;

use DomainFlow\EventSourcingCore\Provider\Integration\ConcurrencyAttributeIntegrationTestCase;
use DomainFlow\EventSourcingCore\Tests\Setup\InMemorySetup;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing()]
final class ConcurrencyAttributeIntegrationTest extends ConcurrencyAttributeIntegrationTestCase
{
    use InMemorySetup;
}
