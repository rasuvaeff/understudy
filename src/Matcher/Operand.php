<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;

/**
 * One side of a combinator: a matcher, or a literal compared by identity.
 *
 * The same pair `not()` accepts. It lives here so that `allOf()` and `anyOf()`
 * read an operand identically: a literal that meant one thing in a conjunction
 * and another in a disjunction would be a trap.
 *
 * @internal
 */
final class Operand
{
    private function __construct() {}

    public static function matches(mixed $operand, mixed $argument): bool
    {
        return $operand instanceof ArgumentMatcher
            ? $operand->matches($argument)
            : $argument === $operand;
    }

    /**
     * @param non-empty-list<mixed> $operands
     *
     * @return non-empty-string
     */
    public static function describeAll(array $operands): string
    {
        return implode(', ', array_map(
            static fn(mixed $operand): string => $operand instanceof ArgumentMatcher
                ? $operand->describe()
                : ArgumentFormatter::format($operand),
            $operands,
        ));
    }
}
