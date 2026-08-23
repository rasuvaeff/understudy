<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

/**
 * How an understudy answers a call no expectation matched.
 *
 * @api
 */
enum Mode
{
    /** Answer with a type-safe default. */
    case Loose;

    /** Fail immediately, naming the method. */
    case Strict;

    /** Delegate to a real instance, recording the call and its outcome. */
    case Forwarding;
}
