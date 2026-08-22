<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;

/**
 * Negates either a literal value or another matcher, so `not(instanceOf(...))`
 * composes as readily as `not(5)`.
 *
 * @internal
 */
final readonly class Negated implements ArgumentMatcher
{
    public function __construct(private mixed $rejected) {}

    #[\Override]
    public function matches(mixed $argument): bool
    {
        return $this->rejected instanceof ArgumentMatcher
            ? !$this->rejected->matches($argument)
            : $argument !== $this->rejected;
    }

    #[\Override]
    public function describe(): string
    {
        return 'not(' . ($this->rejected instanceof ArgumentMatcher
            ? $this->rejected->describe()
            : ArgumentFormatter::format($this->rejected)) . ')';
    }
}
