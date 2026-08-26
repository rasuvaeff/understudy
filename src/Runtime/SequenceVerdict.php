<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

/**
 * What an armed protocol makes of one call.
 *
 * @internal
 */
enum SequenceVerdict
{
    /** No protocol is armed, or none that names this double. */
    case NotWatched;

    /** The step the cursor was on; the cursor has moved past it. */
    case Advanced;

    /**
     * A double under protocol received something that is not the step due —
     * legal only if the test configured it, which the dispatcher decides.
     */
    case NotAStep;

    /** A step, but not the one due. */
    case OutOfTurn;
}
