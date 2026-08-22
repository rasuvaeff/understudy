<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Matcher\AnyArgument;
use Rasuvaeff\Understudy\Matcher\AnyTail;
use Rasuvaeff\Understudy\Matcher\ArrayContaining;
use Rasuvaeff\Understudy\Matcher\BooleanValue;
use Rasuvaeff\Understudy\Matcher\CountBetween;
use Rasuvaeff\Understudy\Matcher\EmptyTail;
use Rasuvaeff\Understudy\Matcher\FloatInRange;
use Rasuvaeff\Understudy\Matcher\IdenticalTo;
use Rasuvaeff\Understudy\Matcher\InstanceOfType;
use Rasuvaeff\Understudy\Matcher\IntInRange;
use Rasuvaeff\Understudy\Matcher\Negated;
use Rasuvaeff\Understudy\Matcher\QueryEquals;
use Rasuvaeff\Understudy\Matcher\Satisfying;
use Rasuvaeff\Understudy\Matcher\StringMatching;

/**
 * Argument matchers, usable only inside a specification closure:
 *
 * ```php
 * when(fn () => $repository->find(Arg::any()))->returns($book);
 * ```
 *
 * Every matcher is declared to return `mixed` rather than the internal
 * `ArgumentMatcher` type. That is not vagueness — it is what the matcher
 * means. A matcher stands in for a value of whatever type the parameter
 * declares, and it is never consumed as a value: the runtime intercepts it
 * while recording the call specification. Declaring the concrete class instead
 * would make `find(Arg::any())` a type error in every IDE and analyser for a
 * contract that says `find(int $id)`, on the first line of the first example
 * anyone copies — while `mixed` is accepted everywhere and stays honest.
 *
 * `understudy-psalm` narrows this to the parameter's declared type, so users
 * of the plugin get a real check rather than `mixed`.
 *
 * @api
 */
final class Arg
{
    private function __construct() {}

    /**
     * Matches any argument, including `null`.
     */
    public static function any(): mixed
    {
        return new AnyArgument();
    }

    /**
     * Matches an `int`, optionally within bounds. A numeric string does not
     * match: the matcher pins the declared type as well as the value.
     */
    public static function int(?int $min = null, ?int $max = null): mixed
    {
        return new IntInRange($min, $max);
    }

    /**
     * Matches a `float`, optionally within bounds. An `int` does not match.
     */
    public static function float(?float $min = null, ?float $max = null): mixed
    {
        return new FloatInRange($min, $max);
    }

    /**
     * Matches a string, optionally against a PCRE pattern.
     *
     * @param non-empty-string|null $matches delimiters included, e.g. `/^ord-/`
     */
    public static function string(?string $matches = null): mixed
    {
        return new StringMatching($matches);
    }

    public static function bool(): mixed
    {
        return new BooleanValue();
    }

    /**
     * Strict identity — for objects, the very same instance.
     */
    public static function same(mixed $value): mixed
    {
        return new IdenticalTo($value);
    }

    /**
     * Negates a literal or another matcher: `not(5)`, `not(Arg::instanceOf(…))`.
     */
    public static function not(mixed $value): mixed
    {
        return new Negated($value);
    }

    /**
     * @param class-string $type
     */
    public static function instanceOf(string $type): mixed
    {
        return new InstanceOfType($type);
    }

    /**
     * Matches whatever the predicate accepts.
     *
     * @param callable(mixed): bool $predicate
     * @param non-empty-string      $description shown in failure messages
     */
    public static function satisfies(callable $predicate, string $description = 'satisfies(…)'): mixed
    {
        return new Satisfying($predicate, $description);
    }

    /**
     * Matches an array containing these entries and possibly more: a list by
     * value, a map by key and value.
     *
     * @param array<array-key, mixed> $entries
     */
    public static function containing(array $entries): mixed
    {
        return new ArrayContaining($entries);
    }

    /**
     * Matches an array or `Countable` whose size is within bounds.
     *
     * @param int<0, max>|null $minimum
     * @param int<0, max>|null $maximum
     */
    public static function count(?int $minimum = null, ?int $maximum = null): mixed
    {
        return new CountBetween($minimum, $maximum);
    }

    /**
     * Matches an object whose getter answers this value.
     *
     * @param non-empty-string $method public, non-static, no required arguments
     */
    public static function which(string $method, mixed $value): mixed
    {
        return new QueryEquals($method, $value);
    }

    /**
     * Requires the variadic tail to be empty. Only valid as the last argument.
     */
    public static function none(): mixed
    {
        return new EmptyTail();
    }

    /**
     * Matches the whole variadic tail, of any length including none. Only
     * valid as the last argument.
     */
    public static function remaining(): mixed
    {
        return new AnyTail();
    }
}
