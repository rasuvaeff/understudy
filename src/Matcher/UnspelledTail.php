<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * What closes a specification that stopped before the contract's optional
 * parameters ran out.
 *
 * Where {@see AnyRest} is written by hand — `Arg::rest()`, the way a
 * specification says it means to stop before a *required* parameter — this one
 * is supplied for the parameters the contract itself allows a caller to omit.
 * It renders as `…`, so the report shows the difference between what the test
 * specified and what it left to the contract.
 *
 * @internal
 */
final readonly class UnspelledTail implements TailMatcher
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
        return '…';
    }
}
