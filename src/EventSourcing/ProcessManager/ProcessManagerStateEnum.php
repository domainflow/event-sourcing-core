<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\ProcessManager;

enum ProcessManagerStateEnum: string
{
    case WAITING = 'waiting';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    /**
     * Check if the state is waiting.
     *
     * @return bool
     */
    public function isWaiting(): bool
    {
        return $this === self::WAITING;
    }

    /**
     * Check if the state is processing.
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this === self::PROCESSING;
    }

    /**
     * Check if the state is completed.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }

    /**
     * Check if the state is failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }

    /**
     * Compare two Process Manager States for equality.
     *
     * @param ProcessManagerStateEnum $other
     * @return bool
     */
    public function equals(
        ProcessManagerStateEnum $other
    ): bool {
        return $this === $other;
    }
}
