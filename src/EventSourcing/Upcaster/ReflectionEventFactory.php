<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Upcaster;

use DateMalformedStringException;
use DateTimeImmutable;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\Event\EventVersion;
use DomainFlow\EventSourcing\Event\OccurredOn;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;
use DomainFlow\EventSourcing\Interface\EventFactoryInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use RuntimeException;

class ReflectionEventFactory implements EventFactoryInterface
{
    /**
     * @throws ReflectionException|DateMalformedStringException
     */
    public function createFromPayload(
        string $eventClass,
        array $payload
    ): DomainEventInterface {
        if (!class_exists($eventClass)) {
            throw new RuntimeException("Event class $eventClass does not exist.");
        }

        $reflection = new ReflectionClass($eventClass);
        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            throw new RuntimeException("Event class $eventClass has no constructor.");
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            $typeObj = $param->getType();
            $type = $typeObj instanceof ReflectionNamedType ? $typeObj->getName() : null;

            if (!array_key_exists($name, $payload) && !$param->isOptional()) {
                throw new RuntimeException("Missing required field '$name' for event $eventClass");
            }

            $value = array_key_exists($name, $payload) ? $payload[$name] : $param->getDefaultValue();

            if ($type !== null && is_a($type, EntityIdentifierInterface::class, true) && is_string($value)) {
                /** @var class-string<EntityIdentifierInterface> $identifierClass */
                $identifierClass = interface_exists($type) ? EntityIdentifier::class : $type;
                $value = $identifierClass::fromString($value);
            }

            if ($type === DateTimeImmutable::class && is_string($value)) {
                $value = OccurredOn::fromString($value);
            }

            if ($type === EventVersion::class && is_numeric($value)) {
                $value = EventVersion::fromInt((int) $value);
            }

            $args[] = $value;
        }

        $event = $reflection->newInstanceArgs($args);

        if (!$event instanceof DomainEventInterface) {
            throw new RuntimeException("Event instance is not a DomainEventInterface.");
        }

        return $event;
    }
}
