<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Event;

use DomainFlow\EventSourcing\Attribute\EventName;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;

/**
 * Maps a stored event name to the class that reconstructs it, and back.
 *
 * Without this, `event_class` holds a fully-qualified class name, and the
 * stored data is tied to the shape of the code that wrote it: moving an event
 * to another namespace, or renaming it, makes every row referring to it
 * unreadable. In an append-only store meant to outlive several refactorings of
 * the code around it, that is the most expensive coupling there is.
 *
 * With it, the code chooses a name — `order.placed` — and may then rename,
 * move or split the class freely.
 *
 * ## Adoption is incremental, and reading is backwards-compatible
 *
 * An unregistered class is written under its own class name, so a codebase can
 * take this on one event at a time. On the way back, a stored value is first
 * looked up as a name and then, failing that, used as a class — so rows
 * written before the registry existed keep resolving with no migration. That
 * fallback is the reason this can be introduced at any point without touching
 * stored data, and the reason it costs nothing to introduce it early.
 */
final class EventTypeRegistry
{
    /** @var array<string, class-string> */
    private array $classByName = [];

    /** @var array<class-string, string> */
    private array $nameByClass = [];

    /**
     * Builds a registry from classes carrying #[EventName].
     *
     * Anything without the attribute is skipped rather than rejected:
     * discovery scans a namespace, and not everything in one is an event.
     *
     * @param iterable<class-string> $classes
     * @return self
     */
    public static function fromClasses(
        iterable $classes
    ): self {
        $registry = new self();

        foreach ($classes as $class) {
            $attributes = (new ReflectionClass($class))->getAttributes(EventName::class);

            foreach ($attributes as $attribute) {
                $registry->register($attribute->newInstance()->name, $class);
            }
        }

        return $registry;
    }

    /**
     * @param string $name The stored name. Permanent once events carry it.
     * @param class-string $class
     * @return void
     */
    public function register(
        string $name,
        string $class
    ): void {
        if ($name === '') {
            throw new InvalidArgumentException('An event name cannot be empty.');
        }

        if (!class_exists($class)) {
            throw new InvalidArgumentException(sprintf(
                'Cannot register event name "%s": class "%s" does not exist.',
                $name,
                $class
            ));
        }

        // Registering the same pair again is what a boot sequence that runs
        // twice looks like, and it is not a mistake.
        if (($this->classByName[$name] ?? null) === $class) {
            return;
        }

        // An ambiguous mapping found at read time costs a stored event; found
        // at registration it costs a stack trace. Refuse both directions.
        if (isset($this->classByName[$name])) {
            throw new InvalidArgumentException(sprintf(
                'Event name "%s" is already registered for "%s" and cannot also mean "%s".',
                $name,
                $this->classByName[$name],
                $class
            ));
        }

        if (isset($this->nameByClass[$class])) {
            throw new InvalidArgumentException(sprintf(
                'Event class "%s" is already registered as "%s" and cannot also be "%s". '
                . 'A class has exactly one stored name, or events written under the old one become unreadable.',
                $class,
                $this->nameByClass[$class],
                $name
            ));
        }

        $this->classByName[$name] = $class;
        $this->nameByClass[$class] = $name;
    }

    /**
     * The name to store for an event class, or the class itself when it has
     * none.
     *
     * @param class-string $class
     * @return string
     */
    public function nameFor(
        string $class
    ): string {
        return $this->nameByClass[$class] ?? $class;
    }

    /**
     * The class a stored name resolves to.
     *
     * @param string $name
     * @return class-string
     */
    public function classFor(
        string $name
    ): string {
        if (isset($this->classByName[$name])) {
            return $this->classByName[$name];
        }

        // Written before the registry, or by a codebase that has not adopted
        // it: the stored value is the class itself.
        if (class_exists($name)) {
            return $name;
        }

        throw new RuntimeException(sprintf(
            'Stored event type "%s" is neither a registered event name nor an existing class. '
            . 'Register it with EventTypeRegistry, or the events written under it cannot be read.',
            $name
        ));
    }

    public function has(
        string $name
    ): bool {
        return isset($this->classByName[$name]);
    }
}
