<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Interface;

/**
 * An event storage that can state whether its writes are being handed to an
 * outbox, so something out of band delivers them.
 *
 * Deliberately **not** a method on `EventStorageInterface`. An adapter outside
 * these four repositories keeps working untouched and simply says nothing,
 * which is read as "no outbox" — so this is an addition rather than a breaking
 * change, and it does not need the pre-1.0 window.
 *
 * It exists because the knowledge sits one layer below the place that has to
 * act on it: the outbox is a constructor argument of each adapter's storage,
 * while the second delivery path — a dispatcher — is given to the facade. With
 * both in place every event goes out twice and nothing reports it. Container
 * wiring cannot close that gap, which is why it is stated here instead.
 *
 * A decorator over an event storage must forward this. One that does not turns
 * `EventSourcingFacade`'s guard into a check that silently passes, which reads
 * as a verified configuration and is worse than no check at all.
 */
interface OutboxBackedStorageInterface
{
    /**
     * Whether writes through this storage are enqueued for an outbox relay to
     * deliver.
     *
     * The configuration in force, not the classes installed: an adapter built
     * with the outbox seam left idle answers `false`.
     *
     * @return bool
     */
    public function deliversThroughOutbox(): bool;
}
