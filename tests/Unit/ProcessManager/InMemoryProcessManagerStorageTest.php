<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\ProcessManager;

use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Exception\ProcessManagerConcurrencyException;
use DomainFlow\EventSourcing\Interface\ProcessManagerStorageInterface;
use DomainFlow\EventSourcing\ProcessManager\InMemoryProcessManagerStorage;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerState;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerStateEnum;
use DomainFlow\EventSourcingCore\Provider\Unit\AbstractProcessManagerStorageTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(ProcessManagerConcurrencyException::class)]
#[CoversClass(EntityIdentifier::class)]
#[CoversClass(ProcessManagerState::class)]
#[CoversClass(InMemoryProcessManagerStorage::class)]
#[UsesClass(ProcessManagerStateEnum::class)]
final class InMemoryProcessManagerStorageTest extends AbstractProcessManagerStorageTestCase
{
    protected function getProcessManagerStorage(): ProcessManagerStorageInterface
    {
        return new InMemoryProcessManagerStorage();
    }
}
