<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcingCore\Tests\Unit\ProcessManager;

use DomainFlow\EventSourcing\ProcessManager\ProcessManagerStateEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessManagerStateEnum::class)]
final class ProcessManagerStateEnumTest extends TestCase
{
    public function testIsWaiting(): void
    {
        $state = ProcessManagerStateEnum::WAITING;

        $this->assertTrue($state->isWaiting());
        $this->assertFalse($state->isProcessing());
        $this->assertFalse($state->isCompleted());
        $this->assertFalse($state->isFailed());
    }

    public function testIsProcessing(): void
    {
        $state = ProcessManagerStateEnum::PROCESSING;

        $this->assertFalse($state->isWaiting());
        $this->assertTrue($state->isProcessing());
        $this->assertFalse($state->isCompleted());
        $this->assertFalse($state->isFailed());
    }

    public function testIsCompleted(): void
    {
        $state = ProcessManagerStateEnum::COMPLETED;

        $this->assertFalse($state->isWaiting());
        $this->assertFalse($state->isProcessing());
        $this->assertTrue($state->isCompleted());
        $this->assertFalse($state->isFailed());
    }

    public function testIsFailed(): void
    {
        $state = ProcessManagerStateEnum::FAILED;

        $this->assertFalse($state->isWaiting());
        $this->assertFalse($state->isProcessing());
        $this->assertFalse($state->isCompleted());
        $this->assertTrue($state->isFailed());
    }

    public function testEquals(): void
    {
        $state1 = ProcessManagerStateEnum::WAITING;
        $state2 = ProcessManagerStateEnum::WAITING;
        $state3 = ProcessManagerStateEnum::PROCESSING;

        $this->assertTrue($state1->equals($state2));
        $this->assertFalse($state1->equals($state3));
    }
}
