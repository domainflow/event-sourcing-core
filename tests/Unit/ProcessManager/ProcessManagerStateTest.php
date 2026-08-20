<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\ProcessManager;

use DateTimeImmutable;
use DateTimeZone;
use DomainFlow\EventSourcing\Entity\EntityIdentifier;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerState;
use DomainFlow\EventSourcing\ProcessManager\ProcessManagerStateEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessManagerStateEnum::class)]
#[CoversClass(ProcessManagerState::class)]
#[CoversClass(EntityIdentifier::class)]
final class ProcessManagerStateTest extends TestCase
{
    public function test_defaultsToWaitingStatus(): void
    {
        $processId = EntityIdentifier::fromString('process-1');
        $state = new ProcessManagerState($processId);

        $this->assertSame(ProcessManagerStateEnum::WAITING, $state->getStatus());
        $this->assertSame((string) $processId, (string) $state->getProcessId());
    }

    public function test_setAndGetStatus(): void
    {
        $state = new ProcessManagerState(EntityIdentifier::fromString('process-1'), ProcessManagerStateEnum::WAITING);

        $this->assertSame(ProcessManagerStateEnum::WAITING, $state->getStatus());

        $state->setStatus(ProcessManagerStateEnum::PROCESSING);
        $this->assertSame(ProcessManagerStateEnum::PROCESSING, $state->getStatus());
    }

    public function test_setAndGetData(): void
    {
        $state = new ProcessManagerState(EntityIdentifier::fromString('process-1'));

        $this->assertSame([], $state->getData());

        $state->setData(['orderId' => 'abc-123', 'itemsShipped' => 2]);
        $this->assertSame(['orderId' => 'abc-123', 'itemsShipped' => 2], $state->getData());
    }

    public function test_setAndGetTimeout(): void
    {
        $timeout = new DateTimeImmutable('2026-03-01 08:30:00.000000', new DateTimeZone('UTC'));
        $state = new ProcessManagerState(EntityIdentifier::fromString('process-1'));

        $this->assertNull($state->getTimeout());

        $state->setTimeout($timeout);

        // The instant, not the object. This used to assert identity, which
        // quietly ruled out normalising the value on the way in — and
        // normalising it is what makes `findTimedOut()` a comparison between
        // two instants rather than between two wall-clock readings.
        $this->assertEquals($timeout, $state->getTimeout());
    }

    /**
     * A timeout set in a local zone is kept as the instant it names. The stored
     * format has no offset to carry the zone, so a value left in local time
     * comes back as a different instant on a host configured differently —
     *  for `occurred_on`, here with a comparison reading it.
     */
    public function test_a_timeout_is_normalised_to_utc(): void
    {
        $state = new ProcessManagerState(EntityIdentifier::fromString('process-1'));

        $state->setTimeout(new DateTimeImmutable('2026-03-01 09:00:00.000000', new DateTimeZone('Asia/Tokyo')));

        $this->assertSame('UTC', $state->getTimeout()?->getTimezone()->getName());
        $this->assertSame('2026-03-01 00:00:00.000000', $state->getTimeout()?->format('Y-m-d H:i:s.u'));
    }

    public function test_toArray(): void
    {
        $processId = EntityIdentifier::fromString('process-1');
        $state = new ProcessManagerState($processId, ProcessManagerStateEnum::PROCESSING);
        $state->setTimeout(new DateTimeImmutable('2026-03-01 09:00:00.000000', new DateTimeZone('Asia/Tokyo')));
        $state->setData(['foo' => 'bar']);

        $expectedArray = [
            'process_id' => 'process-1',
            'status' => ProcessManagerStateEnum::PROCESSING->value,
            'data' => ['foo' => 'bar'],
            // Stated rather than derived from the input: rendering the value
            // the test passed in would agree with itself on any host, which is
            // exactly the agreement that hid the defect.
            'timeout' => '2026-03-01 00:00:00',
            'version' => 0,
        ];

        $this->assertSame($expectedArray, $state->toArray());
    }
}
