<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * @internal
 */
final readonly class AnyTail implements TailMatcher
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
        return 'remaining()';
    }
}
