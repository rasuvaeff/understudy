<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * @internal
 */
final readonly class EmptyTail implements TailMatcher
{
    #[\Override]
    public function matchesTail(array $tail): bool
    {
        return $tail === [];
    }

    #[\Override]
    public function matches(mixed $argument): bool
    {
        // Reached only if used somewhere other than the tail, which the
        // specification rejects before matching.
        return false;
    }

    #[\Override]
    public function describe(): string
    {
        return 'none()';
    }
}
