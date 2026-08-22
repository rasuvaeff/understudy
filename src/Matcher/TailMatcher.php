<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * A matcher that stands for the whole variadic tail rather than one argument,
 * so it changes how many arguments the call is allowed to have. Only valid as
 * the last argument of a specification.
 *
 * @internal
 */
interface TailMatcher extends ArgumentMatcher
{
    /**
     * @param list<mixed> $tail every argument from this position onwards
     */
    public function matchesTail(array $tail): bool;
}
