<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Upcaster;

use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventFactoryInterface;
use DomainFlow\EventSourcing\Interface\EventUpcasterInterface;
use RuntimeException;

final class EventUpcasterRegistry implements EventUpcasterInterface
{
    /**
     * How many times the chain may report a change before the registry gives
     * up. Generous — a real chain settles in a handful of passes — because the
     * cap is a guard against a broken upcaster, not a design limit.
     */
    private const int MAX_UPCASTING_PASSES = 100;

    /** @var EventUpcasterInterface[] */
    private array $upcasters = [];

    private ?EventFactoryInterface $factory = null;

    /**
     * Set the event factory.
     *
     * @param EventFactoryInterface $factory
     * @return void
     */
    public function setFactory(
        EventFactoryInterface $factory
    ): void {
        $this->factory = $factory;
    }

    /**
     * Register an upcaster.
     *
     * @param EventUpcasterInterface $upcaster
     * @return void
     */
    public function register(
        EventUpcasterInterface $upcaster
    ): void {
        $this->upcasters[] = $upcaster;
    }

    /**
     * Upcast the event to the latest version.
     *
     * @param string $eventType
     * @param array<string, mixed> $data
     * @return DomainEventInterface
     */
    public function upcast(
        string $eventType,
        array $data
    ): DomainEventInterface {
        $currentType = $eventType;
        $currentData = $data;
        $passes = 0;

        while (true) {
            if (++$passes > self::MAX_UPCASTING_PASSES) {
                throw new RuntimeException(sprintf(
                    'Upcasting "%s" did not settle after %d passes. An upcaster is most likely returning a '
                    . 'payload that differs on every call, so the registry keeps seeing a change.',
                    $eventType,
                    self::MAX_UPCASTING_PASSES
                ));
            }

            $wasUpcasted = false;

            foreach ($this->upcasters as $upcaster) {
                if ($upcaster->supports($currentType)) {
                    $event = $upcaster->upcast($currentType, $currentData);
                    $newType = $event::class;
                    $newData = $event->toArray();

                    if ($newType !== $currentType || $newData !== $currentData) {
                        $currentType = $newType;
                        $currentData = $newData;
                        $wasUpcasted = true;
                        break;
                    }
                }
            }

            if (!$wasUpcasted) {
                if ($this->factory !== null) {
                    return $this->factory->createFromPayload($currentType, $currentData);
                }
                throw new RuntimeException('Event factory is not set for EventUpcasterRegistry.');

            }
        }
    }

    /**
     * Check if the event type is supported by any upcaster.
     *
     * @param string $eventType
     * @return bool
     */
    public function supports(
        string $eventType
    ): bool {
        foreach ($this->upcasters as $upcaster) {
            if ($upcaster->supports($eventType)) {
                return true;
            }
        }

        return false;
    }
}
