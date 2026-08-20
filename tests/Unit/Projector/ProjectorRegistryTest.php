<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Projector;

use DomainFlow\EventSourcing\Interface\EventDispatcherInterface;
use DomainFlow\EventSourcing\Interface\ProjectorInterface;
use DomainFlow\EventSourcing\Projector\ProjectorRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use stdClass;

#[CoversClass(ProjectorRegistry::class)]
final class ProjectorRegistryTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function test_addStoresProjector(): void
    {
        $projector = $this->createStub(ProjectorInterface::class);

        $registry = new ProjectorRegistry();
        $registry->add($projector);

        $reflection = new ReflectionClass($registry);
        $prop = $reflection->getProperty('projectors');

        $stored = $prop->getValue($registry);

        $this->assertCount(1, $stored);
        $this->assertSame($projector, $stored[0]);
    }

    /**
     * @throws Exception
     */
    public function test_registerAllThrowsIfNonProjectorIsPresent(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);

        $registry = new ProjectorRegistry();

        $reflection = new ReflectionClass($registry);
        $prop = $reflection->getProperty('projectors');
        $prop->setValue($registry, [new stdClass()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid projector instance in registry.');
        $registry->registerAll($dispatcher);
    }

    /**
     * @throws Exception
     */
    public function test_clearEmptiesRegistry(): void
    {
        $projector = $this->createStub(ProjectorInterface::class);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('register');

        $registry = new ProjectorRegistry();
        $registry->add($projector);
        $registry->clear();
        $registry->registerAll($dispatcher);
    }

    /**
     * @throws Exception
     */
    public function test_addAndRegisterAll(): void
    {
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $projector1 = $this->createStub(ProjectorInterface::class);
        $projector2 = $this->createStub(ProjectorInterface::class);

        $calls = [];
        $dispatcher->method('register')
            ->willReturnCallback(function ($projector) use (&$calls) {
                $calls[] = $projector;
            });

        $registry = new ProjectorRegistry();
        $registry->add($projector1);
        $registry->add($projector2);
        $registry->registerAll($dispatcher);

        $this->assertSame([$projector1, $projector2], $calls);
    }
}
