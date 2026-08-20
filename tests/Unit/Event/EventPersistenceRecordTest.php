<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\Event;

use DomainFlow\EventSourcing\Event\EventPersistenceRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventPersistenceRecord::class)]
final class EventPersistenceRecordTest extends TestCase
{
    public function test_toArray_returns_all_fields(): void
    {
        $data = DummyEventData::getData();
        $record = new EventPersistenceRecord($data);

        $this->assertSame($data, $record->toArray());
    }

    public function test_getValues_returns_all_fields(): void
    {
        $data = DummyEventData::getData();
        $record = new EventPersistenceRecord($data);

        $this->assertSame($data, $record->getValues());
    }

    public function test_getPersistenceColumns_returns_keys(): void
    {
        $data = DummyEventData::getData();
        $record = new EventPersistenceRecord($data);

        $this->assertSame(array_keys($data), $record->getPersistenceColumns());
    }

    public function test_getPersistencePlaceholders_returns_colon_prefixed_keys(): void
    {
        $data = DummyEventData::getData();
        $record = new EventPersistenceRecord($data);

        $expected = array_map(fn ($key) => ":$key", array_keys($data));
        $this->assertSame($expected, $record->getPersistencePlaceholders());
    }

    public function test_fromArray_creates_instance_with_same_data(): void
    {
        $data = DummyEventData::getData();
        $record = EventPersistenceRecord::fromArray($data);

        $this->assertSame($data, $record->toArray());
    }
}

final class DummyEventData
{
    public static function getData(): array
    {
        return [
            'aggregateId' => 'user-123',
            'version' => 5,
            'type' => 'UserRegistered',
            'occurredOn' => '2024-01-01T00:00:00Z',
            'payload' => ['email' => 'test@example.com'],
        ];
    }
}
