<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Integration;

use DomainFlow\EventSourcing\Interface\EventEntryFactoryInterface;
use DomainFlow\EventSourcing\Storage\InMemoryEventStorage;
use DomainFlow\EventSourcingCore\Provider\Integration\CustomFieldsStorageTestCase;
use DomainFlow\EventSourcingCore\Tests\Setup\InMemorySetup;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing()]
final class CustomFieldsStorageTest extends CustomFieldsStorageTestCase
{
    use InMemorySetup;

    public function getStorageWithFactory(
        EventEntryFactoryInterface $factory = null
    ): InMemoryEventStorage {
        return new InMemoryEventStorage(
            $factory
        );
    }
}
