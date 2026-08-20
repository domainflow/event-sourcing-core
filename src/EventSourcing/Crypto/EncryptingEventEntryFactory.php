<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Crypto;

use DomainFlow\EventSourcing\Attribute\DataSubjectId;
use DomainFlow\EventSourcing\Attribute\PersonalData;
use DomainFlow\EventSourcing\Event\EventPersistenceRecord;
use DomainFlow\EventSourcing\Interface\DomainEventInterface;
use DomainFlow\EventSourcing\Interface\EventEntryFactoryInterface;
use JsonException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * Crypto-shredding, as a decorator around any `EventEntryFactoryInterface`.
 *
 * An event store is append-only; the right to erasure is not. Deleting
 * personal data would mean rewriting history, which is the one thing an event
 * store must not do. So the data is encrypted with a key held per data
 * subject, and erasure destroys the key: the event stays exactly as written,
 * and what it says becomes unreadable.
 *
 * **No adapter knows about any of this.** Every storage takes its entry
 * factory as an instance dependency, so wrapping it is the whole
 * integration — the same seam  and  went through.
 *
 * The payload is rewritten around the inner factory rather than the event
 * being mutated before it: events are immutable, and the record is the last
 * point where the data is still this package's to shape. It also means this
 * works against MongoDB's factory, which stores a payload as a subdocument
 * rather than as an encoded string — the value is rewritten in whichever shape
 * it arrives in.
 *
 * **Encrypted values are self-describing.** A field is stored as an envelope
 * naming its subject, so reading needs no attributes, no class resolution and
 * no registry: a value that was personal when it was written stays decryptable
 * even if the attribute is later removed from the class. That matters more
 * here than anywhere else, because the alternative is data nobody can read and
 * nobody can erase.
 */
final readonly class EncryptingEventEntryFactory implements EventEntryFactoryInterface
{
    /** Names the shape rather than the algorithm, so the cipher can change under it. */
    private const string ENVELOPE_MARKER = '__domainflow_personal_data';

    private const string ENVELOPE_VERSION = '1';

    public function __construct(
        private EventEntryFactoryInterface $inner,
        private PersonalDataKeyStoreInterface $keys,
        private CipherInterface $cipher
    ) {
    }

    /**
     * @param DomainEventInterface $event
     * @throws JsonException|RuntimeException
     * @return EventPersistenceRecord
     */
    public function createFromDomainEvent(
        DomainEventInterface $event
    ): EventPersistenceRecord {
        $record = $this->inner->createFromDomainEvent($event);
        $personal = $this->personalProperties($event);

        if ($personal === []) {
            return $record;
        }

        $subjectId = $this->subjectIdOf($event);
        $key = $this->keys->ensureKeyFor($subjectId);

        return $this->rewritePayload(
            $record,
            fn (array $payload): array => $this->seal($payload, $personal, $subjectId, $key, $event::class)
        );
    }

    /**
     * @param EventPersistenceRecord $record
     * @throws JsonException
     * @return DomainEventInterface
     */
    public function recordToDomainEvent(
        EventPersistenceRecord $record
    ): DomainEventInterface {
        return $this->inner->recordToDomainEvent(
            $this->rewritePayload($record, fn (array $payload): array => $this->open($payload))
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<array{name: string, key: string, value: mixed}> $personal
     * @param string $subjectId
     * @param string $key
     * @param string $eventClass
     * @throws RuntimeException
     * @return array<string, mixed>
     */
    private function seal(
        array $payload,
        array $personal,
        string $subjectId,
        string $key,
        string $eventClass
    ): array {
        foreach ($personal as $field) {
            if (!array_key_exists($field['key'], $payload)) {
                continue;
            }

            $value = $payload[$field['key']];

            if (!is_string($value)) {
                // Refused now rather than at erasure. Erasure has to put
                // RedactedValue::MARKER where the value was, and a property
                // that cannot hold a string would store fine today and fail to
                // read back on the day someone asks to be forgotten.
                throw new RuntimeException(sprintf(
                    '%s::$%s is marked #[PersonalData], so it must be a string; %s given.',
                    $eventClass,
                    $field['name'],
                    get_debug_type($value)
                ));
            }

            $payload[$field['key']] = [
                self::ENVELOPE_MARKER => self::ENVELOPE_VERSION,
                'subject' => $subjectId,
                'ciphertext' => $this->cipher->encrypt($value, $key),
            ];
        }

        // After every seal, not per field: a field's value can sit under
        // another field's key, and that key may not have been sealed yet.
        foreach ($personal as $field) {
            $this->assertNothingSurvivesInTheClear($payload, $field, $eventClass);
        }

        return $payload;
    }

    /**
     * Refuses a payload that still carries a personal value in the clear
     *.
     *
     * The hole this closes was silent. Sealing matched `#[PersonalData]`
     * against the payload by **property name**, and a key that did not carry
     * that name was skipped — so an event storing `$email` under
     * `'email_address'` was written unencrypted, with nothing failing and
     * nothing to see except the stored row. What the attribute promises is
     * about the row, so the row is what gets checked.
     *
     * Refused rather than sealed by value: several keys can hold the same
     * string, one of them may be the `#[DataSubjectId]` that has to stay
     * readable, and picking wrong is unrecoverable once the row is written.
     * The event's author knows which key it is, and `#[PersonalData(key: …)]`
     * is where they say so.
     *
     * Only non-empty strings are searched for. The empty string leaks nothing,
     * and the attribute is a string contract in the first place — matching on
     * scalars would let `1` collide with a version and refuse an event over
     * nothing.
     *
     * @param array<string, mixed> $payload
     * @param array{name: string, key: string, value: mixed} $field
     * @param string $eventClass
     * @throws RuntimeException
     * @return void
     */
    private function assertNothingSurvivesInTheClear(
        array $payload,
        array $field,
        string $eventClass
    ): void {
        if (!is_string($field['value']) || $field['value'] === '') {
            return;
        }

        $found = $this->keysHolding($payload, $field['value']);

        if ($found === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s::$%s is marked #[PersonalData] but its value is in the payload under key %s, '
            . 'where it would be stored in the clear. A payload key is part of your stored data format '
            . 'and does not have to match the property that holds it — name it with '
            . "#[PersonalData(key: '%s')], or store the value under '%s'.",
            $eventClass,
            $field['name'],
            implode(', ', array_map(static fn (string $path): string => "'" . $path . "'", $found)),
            $found[0],
            $field['name']
        ));
    }

    /**
     * Every place in the payload holding this exact string, as dotted paths.
     *
     * Nested, because a payload is a document: a value copied into a
     * subdocument is as readable as one at the top level, and reporting only
     * the top-level key would send the reader looking in the wrong place.
     *
     * @param array<array-key, mixed> $payload
     * @param string $value
     * @param string $prefix
     * @return list<string>
     */
    private function keysHolding(
        array $payload,
        string $value,
        string $prefix = ''
    ): array {
        $found = [];

        foreach ($payload as $name => $item) {
            $path = $prefix === '' ? (string) $name : $prefix . '.' . $name;

            if ($item === $value) {
                $found[] = $path;
                continue;
            }

            if (is_array($item)) {
                $found = [...$found, ...$this->keysHolding($item, $value, $path)];
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function open(
        array $payload
    ): array {
        foreach ($payload as $name => $value) {
            $envelope = $this->asEnvelope($value);

            if ($envelope === null) {
                continue;
            }

            [$subjectId, $ciphertext] = $envelope;
            $key = $this->keys->keyFor($subjectId);

            // A missing key is the answer, not a failure: it is what "forget
            // me" looks like from here. A key that is present and does not
            // work is a fault and keeps its own exception, or a misconfigured
            // key store would read as permanent, silent erasure.
            $payload[$name] = $key === null
                ? RedactedValue::MARKER
                : $this->cipher->decrypt($ciphertext, $key);
        }

        return $payload;
    }

    /**
     * @param mixed $value
     * @return array{0: string, 1: string}|null
     */
    private function asEnvelope(
        mixed $value
    ): ?array {
        if (!is_array($value) || ($value[self::ENVELOPE_MARKER] ?? null) !== self::ENVELOPE_VERSION) {
            return null;
        }

        $subjectId = $value['subject'] ?? null;
        $ciphertext = $value['ciphertext'] ?? null;

        return is_string($subjectId) && is_string($ciphertext) ? [$subjectId, $ciphertext] : null;
    }

    /**
     * Applies a transformation to the payload in whichever shape it arrives —
     * an encoded string from Core's factory, a native array from MongoDB's.
     *
     * @param EventPersistenceRecord $record
     * @param callable(array<string, mixed>): array<string, mixed> $transform
     * @throws JsonException|RuntimeException
     * @return EventPersistenceRecord
     */
    private function rewritePayload(
        EventPersistenceRecord $record,
        callable $transform
    ): EventPersistenceRecord {
        $fields = $record->toArray();
        $payload = $fields['payload'] ?? null;

        if (is_string($payload)) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $fields['payload'] = json_encode($transform($decoded), JSON_THROW_ON_ERROR);

            return EventPersistenceRecord::fromArray($fields);
        }

        if (is_array($payload)) {
            $decoded = [];

            foreach ($payload as $name => $value) {
                $decoded[(string) $name] = $value;
            }

            $fields['payload'] = $transform($decoded);

            return EventPersistenceRecord::fromArray($fields);
        }

        // Neither shape any factory produces. Left alone, the record would
        // travel on and fail at hydration naming the event class — while the
        // thing that is actually wrong is the stored row.
        throw new RuntimeException(sprintf(
            'A stored event payload must be an encoded string or a document; %s given.',
            get_debug_type($payload)
        ));
    }

    /**
     * The personal fields of an event: the property that declares one, the
     * payload key it is stored under, and the value it holds.
     *
     * The key is the attribute's when it names one and the property's name
     * otherwise — the common case, where an event's `toArray()` uses its own
     * property names. The value comes along because what has to be checked
     * afterwards is whether it survived the sealing anywhere in the payload.
     *
     * @param DomainEventInterface $event
     * @return list<array{name: string, key: string, value: mixed}>
     */
    private function personalProperties(
        DomainEventInterface $event
    ): array {
        $fields = [];

        foreach ($this->propertiesOf($event) as $property) {
            $attributes = $property->getAttributes(PersonalData::class);

            if ($attributes === []) {
                continue;
            }

            $name = $property->getName();

            $fields[] = [
                'name' => $name,
                'key' => $attributes[0]->newInstance()->key ?? $name,
                'value' => $property->isInitialized($event) ? $property->getValue($event) : null,
            ];
        }

        return $fields;
    }

    /**
     * @param DomainEventInterface $event
     * @throws RuntimeException
     * @return string
     */
    private function subjectIdOf(
        DomainEventInterface $event
    ): string {
        foreach ($this->propertiesOf($event) as $property) {
            if ($property->getAttributes(DataSubjectId::class) === []) {
                continue;
            }

            $value = $property->getValue($event);

            if (is_string($value) && $value !== '') {
                return $value;
            }

            throw new RuntimeException(sprintf(
                '%s::$%s is the #[DataSubjectId] and must hold a non-empty string; %s given.',
                $event::class,
                $property->getName(),
                is_string($value) ? 'an empty string' : get_debug_type($value)
            ));
        }

        // Without a subject there is nothing to key erasure on, so the data
        // could be stored and never erased. Refused at the point where the
        // mistake is still cheap.
        throw new RuntimeException(sprintf(
            '%s declares #[PersonalData] but no #[DataSubjectId], so its data could never be erased.',
            $event::class
        ));
    }

    /**
     * @param DomainEventInterface $event
     * @return list<ReflectionProperty>
     */
    private function propertiesOf(
        DomainEventInterface $event
    ): array {
        return array_values((new ReflectionClass($event))->getProperties());
    }
}
