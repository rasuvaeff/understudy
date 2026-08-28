<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * "The arguments before me matter, the rest of the arity does not."
 *
 * The tail matcher `Arg::rest()` builds. Where {@see AnyTail} stands for the
 * variadic tail a method declares, this one stands for declared parameters the
 * specification chose not to spell out — it is the only matcher whose presence
 * lets a specification pass fewer arguments than the method requires.
 *
 * @internal
 */
final readonly class AnyRest implements TailMatcher
{
    #[\Override]
    public function matchesTail(array $tail): bool
    {
        return true;
    }

    #[\Override]
    public function matches(mixed $argument): bool
    {
        return true;
    }

    #[\Override]
    public function describe(): string
    {
        return 'rest()';
    }
}
