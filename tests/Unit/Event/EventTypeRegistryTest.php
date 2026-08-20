<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Event;

use DomainFlow\EventSourcing\Attribute\EventName;
use DomainFlow\EventSourcing\Event\EventTypeRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(EventTypeRegistry::class)]
#[CoversClass(EventName::class)]
final class EventTypeRegistryTest extends TestCase
{
    /**
     * The reason the class exists. A stored event survives its class being
     * renamed or moved, because what was written is a name the code chooses
     * rather than a name the autoloader owns.
     *
     * The rename is expressed here by registering the same logical name
     * against a different class — which is exactly what a refactoring looks
     * like from the store's side.
     */
    public function test_aStoredNameStillResolvesAfterTheClassIsRenamed(): void
    {
        $before = new EventTypeRegistry();
        $before->register('order.placed', RegistryOrderPlaced::class);

        $stored = $before->nameFor(RegistryOrderPlaced::class);
        $this->assertSame('order.placed', $stored);

        // The class moves namespace. Only the registry changes.
        $after = new EventTypeRegistry();
        $after->register('order.placed', RegistryOrderPlacedRenamed::class);

        $this->assertSame(RegistryOrderPlacedRenamed::class, $after->classFor($stored));
    }

    public function test_anUnregisteredClassKeepsItsFullyQualifiedName(): void
    {
        $registry = new EventTypeRegistry();

        $this->assertSame(
            RegistryOrderPlaced::class,
            $registry->nameFor(RegistryOrderPlaced::class),
            'Adopting the registry has to be possible one event at a time.'
        );
    }

    public function test_aStoredFullyQualifiedNameStillReads(): void
    {
        $registry = new EventTypeRegistry();
        $registry->register('order.placed', RegistryOrderPlaced::class);

        $this->assertSame(
            RegistryOrderPlacedRenamed::class,
            $registry->classFor(RegistryOrderPlacedRenamed::class),
            'Rows written before the registry existed must keep resolving.'
        );
    }

    public function test_anUnknownNameThatIsNotAClassSaysSo(): void
    {
        $registry = new EventTypeRegistry();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('order.vanished');

        $registry->classFor('order.vanished');
    }

    public function test_oneNameCannotMeanTwoClasses(): void
    {
        $registry = new EventTypeRegistry();
        $registry->register('order.placed', RegistryOrderPlaced::class);

        $this->expectException(InvalidArgumentException::class);

        $registry->register('order.placed', RegistryOrderPlacedRenamed::class);
    }

    public function test_oneClassCannotHaveTwoNames(): void
    {
        $registry = new EventTypeRegistry();
        $registry->register('order.placed', RegistryOrderPlaced::class);

        $this->expectException(InvalidArgumentException::class);

        $registry->register('order.created', RegistryOrderPlaced::class);
    }

    /**
     * Registering the same pair twice is how a boot sequence that runs twice
     * behaves, and it is not an error.
     */
    public function test_registeringTheSamePairTwiceIsHarmless(): void
    {
        $registry = new EventTypeRegistry();
        $registry->register('order.placed', RegistryOrderPlaced::class);
        $registry->register('order.placed', RegistryOrderPlaced::class);

        $this->assertSame('order.placed', $registry->nameFor(RegistryOrderPlaced::class));
    }

    public function test_itBuildsItselfFromTheAttribute(): void
    {
        $registry = EventTypeRegistry::fromClasses([
            RegistryOrderPlaced::class,
            RegistryOrderPlacedRenamed::class,
        ]);

        $this->assertSame('order.placed', $registry->nameFor(RegistryOrderPlaced::class));
        $this->assertSame(RegistryOrderPlaced::class, $registry->classFor('order.placed'));
    }

    /**
     * A class without the attribute is skipped rather than rejected: discovery
     * scans a namespace, and not everything in one is an event.
     */
    public function test_discoveryIgnoresAClassWithoutTheAttribute(): void
    {
        $registry = EventTypeRegistry::fromClasses([RegistryOrderPlacedRenamed::class]);

        $this->assertFalse($registry->has('order.placed'));
    }

    public function test_hasReportsWhatIsRegistered(): void
    {
        $registry = new EventTypeRegistry();
        $registry->register('order.placed', RegistryOrderPlaced::class);

        $this->assertTrue($registry->has('order.placed'));
        $this->assertFalse($registry->has('order.shipped'));
    }

    public function test_aNameMustNotBeEmpty(): void
    {
        $registry = new EventTypeRegistry();

        $this->expectException(InvalidArgumentException::class);

        $registry->register('', RegistryOrderPlaced::class);
    }

    public function test_aRegisteredClassMustExist(): void
    {
        $registry = new EventTypeRegistry();

        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore-next-line intentionally not a class */
        $registry->register('order.placed', 'Not\\A\\Class');
    }
}

#[EventName('order.placed')]
final class RegistryOrderPlaced
{
}

final class RegistryOrderPlacedRenamed
{
}
