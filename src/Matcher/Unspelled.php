<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * The stand-in for one optional parameter a specification did not spell.
 *
 * A contract that declares a parameter optional says a caller may leave it
 * out; a specification that leaves it out says nothing about it, and this is
 * that "nothing". It accepts every value, and renders as `…` rather than as
 * `any()` so a failure message distinguishes the position the test wrote a
 * matcher for from the position it never mentioned.
 *
 * @internal
 */
final readonly class Unspelled implements ArgumentMatcher
{
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
