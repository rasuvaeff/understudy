<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * @internal
 */
final readonly class BooleanValue implements ArgumentMatcher
{
    #[\Override]
    public function matches(mixed $argument): bool
    {
        return is_bool($argument);
    }

    #[\Override]
    public function describe(): string
    {
        return 'bool()';
    }
}
