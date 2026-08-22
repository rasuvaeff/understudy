<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;

/**
 * @internal
 */
final readonly class IdenticalTo implements ArgumentMatcher
{
    public function __construct(private mixed $expected) {}

    #[\Override]
    public function matches(mixed $argument): bool
    {
        return $argument === $this->expected;
    }

    #[\Override]
    public function describe(): string
    {
        return 'same(' . ArgumentFormatter::format($this->expected) . ')';
    }
}
