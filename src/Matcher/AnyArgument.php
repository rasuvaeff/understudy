<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * @internal
 */
final readonly class AnyArgument implements ArgumentMatcher
{
    #[\Override]
    public function matches(mixed $argument): bool
    {
        return true;
    }

    #[\Override]
    public function describe(): string
    {
        return 'any()';
    }
}
