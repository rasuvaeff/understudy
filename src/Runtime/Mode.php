<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

/**
 * How an understudy answers a call no expectation matched.
 *
 * `@internal` like the rest of `Runtime\`, and not by demotion: this enum
 * never appears in a public signature. A user says `Understudy::strict()`,
 * `lean()` or `forwarding()`, and the case is what those write down — so an
 * `@api` on it promised a contract nobody could reach and no document
 * described.
 *
 * @internal
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
