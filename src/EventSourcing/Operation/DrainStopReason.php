<?php

declare(strict_types=1);

namespace DomainFlow\EventSourcing\Operation;

/**
 * Why a drain ended.
 *
 * Reported rather than logged, because the three are operationally different:
 * a run that hit its pass or time bound did what it was told, while one that
 * stopped on request was shut down and may have left work behind.
 */
enum DrainStopReason
{
    /** The configured number of passes was reached. */
    case MaxPasses;

    /** The configured wall-clock budget was spent. */
    case MaxSeconds;

    /** `stop()` was called — a signal handler, typically. */
    case StopRequested;
}
