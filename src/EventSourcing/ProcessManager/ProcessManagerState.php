<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\ProcessManager;

use DateTimeImmutable;
use DateTimeZone;
use DomainFlow\EventSourcing\Interface\EntityIdentifierInterface;

final class ProcessManagerState
{
    private ProcessManagerStateEnum $status;

    /** @var array<string, mixed> */
    private array $data = [];

    private ?DateTimeImmutable $timeout = null;

    /**
     * The version this state was loaded at. Zero means it has never been
     * stored, which is what makes "insert only if absent" expressible with the
     * same comparison as every other write.
     */
    private int $version = 0;

    public function __construct(
        private readonly EntityIdentifierInterface $processId,
        ProcessManagerStateEnum $status = ProcessManagerStateEnum::WAITING,
        int $version = 0
    ) {
        $this->status = $status;
        $this->version = $version;
    }

    /**
     * The version this state was loaded at.
     *
     * A storage rejects a write whose version no longer matches what is
     * stored, which is what stops two workers handling events for the same
     * saga from overwriting each other. `ProcessManagerRepository` exists
     * precisely for that situation — reload per event, typically in a queue
     * worker — so an unguarded read-modify-write there was the dangerous kind.
     *
     * @return int
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Records the version a storage has just written.
     *
     * Called by storage implementations after a successful write, not by
     * consumers: a long-lived process manager stores its state repeatedly, and
     * without this the second store would still be presenting the first
     * store's version and would be rejected as stale.
     *
     * @param int $version
     * @return void
     */
    public function markPersisted(
        int $version
    ): void {
        $this->version = $version;
    }

    /**
     * The correlation ID identifying which process instance this state belongs to.
     *
     * @return EntityIdentifierInterface
     */
    public function getProcessId(): EntityIdentifierInterface
    {
        return $this->processId;
    }

    /**
     * Set the process manager's lifecycle status.
     *
     * @param ProcessManagerStateEnum $status
     * @return void
     */
    public function setStatus(
        ProcessManagerStateEnum $status
    ): void {
        $this->status = $status;
    }

    /**
     * Get the process manager's lifecycle status.
     *
     * @return ProcessManagerStateEnum
     */
    public function getStatus(): ProcessManagerStateEnum
    {
        return $this->status;
    }

    /**
     * Replace the process manager's business data payload.
     *
     * @param array<string, mixed> $data
     * @return void
     */
    public function setData(
        array $data
    ): void {
        $this->data = $data;
    }

    /**
     * Get the process manager's business data payload.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Set the timeout for the process manager state, normalised to UTC.
     *
     * The stored format carries no offset, so the instant has to be pinned
     * here or not at all. Two services in one cluster with different
     * `date.timezone` settings would otherwise write values into the same
     * column that cannot be compared, and `findTimedOut()` — which is nothing
     * but that comparison — would fire a saga hours early or hours late
     * depending on which host happened to set the timeout.
     *
     * @param DateTimeImmutable $timeout
     * @return void
     */
    public function setTimeout(
        DateTimeImmutable $timeout
    ): void {
        $this->timeout = $timeout->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Remove the timeout.
     *
     * A saga that has handled its timeout has to be able to drop it, and there
     * was no way to: setTimeout() only ever set one. The adapters already
     * rewrite the stored state rather than merging into it, precisely so a
     * cleared timeout does not linger — but with no way to clear one, that
     * behaviour was unreachable.
     *
     * @return void
     */
    public function clearTimeout(): void
    {
        $this->timeout = null;
    }

    /**
     * Get the timeout for the process manager state.
     *
     * @return DateTimeImmutable|null
     */
    public function getTimeout(): ?DateTimeImmutable
    {
        return $this->timeout;
    }

    /**
     * Convert the process manager state to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'process_id' => (string) $this->processId,
            'status' => $this->status->value,
            'data' => $this->data,
            'timeout' => $this->timeout?->format('Y-m-d H:i:s'),
            'version' => $this->version,
        ];
    }
}
