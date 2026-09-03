<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Matcher\AllOf;
use Rasuvaeff\Understudy\Matcher\AnyArgument;
use Rasuvaeff\Understudy\Matcher\AnyOf;
use Rasuvaeff\Understudy\Matcher\AnyRest;
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
use Rasuvaeff\Understudy\Matcher\TailMatcher;

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
        self::bounds('int', $min, $max);

        return new IntInRange($min, $max);
    }

    /**
     * Matches a `float`, optionally within bounds. An `int` does not match.
     */
    public static function float(?float $min = null, ?float $max = null): mixed
    {
        self::bounds('float', $min, $max);

        return new FloatInRange($min, $max);
    }

    /**
     * Matches a string, optionally against a PCRE pattern.
     *
     * @param non-empty-string|null $matches delimiters included, e.g. `/^ord-/`
     */
    public static function string(?string $matches = null): mixed
    {
        if ($matches !== null) {
            self::pattern($matches);
        }

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
     * Matches an argument every operand accepts. An operand is a matcher or a
     * literal, the same pair `not()` takes.
     *
     * @param mixed ...$operands at least one; a tail matcher is not one of them
     */
    public static function allOf(mixed ...$operands): mixed
    {
        return new AllOf(self::operands('allOf', array_values($operands)));
    }

    /**
     * Matches an argument at least one operand accepts. With literals it reads
     * as a set: `anyOf('draft', 'review')`.
     *
     * @param mixed ...$operands at least one; a tail matcher is not one of them
     */
    public static function anyOf(mixed ...$operands): mixed
    {
        return new AnyOf(self::operands('anyOf', array_values($operands)));
    }

    /**
     * @param class-string $type
     */
    public static function instanceOf(string $type): mixed
    {
        return new InstanceOfType($type);
    }

    /**
     * A typed argument captor. Its `capture()` goes where the argument to
     * observe goes, matches like `instanceOf()` — or like `any()` for the
     * untyped form — and records the value once the whole specification
     * matched:
     *
     * ```php
     * $options = Arg::captor(DeliveryOptions::class);
     * when(fn () => $store->temporaryUrl(Arg::any(), Arg::any(), $options->capture()))
     *     ->returns('https://…');
     *
     * $subject->run();
     *
     * $options->last();   // DeliveryOptions, typed
     * $options->all();    // list<DeliveryOptions>, in call order
     * ```
     *
     * The typed replacement for reading `args[N]` out of the call log:
     * `last()` and `all()` carry the class through, so no `instanceof`
     * narrowing ritual is needed at the read site.
     *
     * @template T of object
     *
     * @param class-string<T>|null $class
     *
     * @return ($class is null ? Captor<mixed> : Captor<T>)
     */
    public static function captor(?string $class = null): Captor
    {
        return new Captor($class);
    }

    /**
     * Matches whatever the predicate accepts.
     *
     * A predicate that throws is not caught, and that is the decision rather
     * than an omission. `Arg::which()` swallows a throwing getter because the
     * getter belongs to the argument — foreign code, reached while the subject
     * is running, where "it threw" can only mean "not this one". A predicate
     * is the test's own code: an exception in it is a broken test, and turning
     * that into a quiet mismatch would report the symptom (an expectation
     * never met) instead of the cause.
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
        self::bounds('count', $minimum, $maximum);

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
     * A range refused rather than silently weakened: a maximum below the
     * minimum describes an empty range, so the matcher would answer `false`
     * to every argument and an `expect()` holding it could never be met. The
     * same rule `Cardinality::between()` follows.
     */
    /**
     * @param non-empty-string $matcher
     */
    private static function bounds(string $matcher, int|float|null $minimum, int|float|null $maximum): void
    {
        if ($minimum !== null && $maximum !== null && $maximum < $minimum) {
            throw InvalidCallSpecification::invertedBounds($matcher, $minimum, $maximum);
        }
    }

    /**
     * A PCRE pattern compiled once, here, rather than on every call from
     * inside the code under test — where a broken one raises PHP's own
     * warning and answers `false` to every argument, which reads as "the
     * argument was wrong" and is the one thing a matcher must never do.
     *
     * The compile runs under an error handler of our own so that the probe
     * does not raise the very warning it exists to prevent, and so that
     * PCRE's reason reaches the message instead of being thrown away. Nothing
     * between the two handler calls can throw — `preg_match()` reports a bad
     * pattern through the error channel our handler is holding — so there is
     * no `finally` to unwind.
     */
    /**
     * @param non-empty-string $pattern
     */
    private static function pattern(string $pattern): void
    {
        $reason = null;

        set_error_handler(static function (int $severity, string $message) use (&$reason): bool {
            $reason = $message;

            return true;
        });

        $compiled = preg_match($pattern, '') !== false;
        restore_error_handler();

        if (!$compiled) {
            throw InvalidCallSpecification::invalidPattern($pattern, self::reason($reason));
        }
    }

    /**
     * PCRE's own complaint, without the function name PHP prefixes it with.
     *
     * @return non-empty-string|null
     */
    private static function reason(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $stripped = preg_replace('/^preg_match\(\):\s*/', '', $message) ?? $message;

        return $stripped === '' ? null : $stripped;
    }

    /**
     * A combinator's operands, refused rather than silently weakened.
     *
     * An empty list makes `allOf()` match everything and `anyOf()` nothing —
     * always a mistake, usually a spread of an empty array. A tail matcher
     * answers `true` to every single argument, so holding one would turn the
     * combinator into a no-op operand inside a conjunction and into a
     * match-anything inside a disjunction.
     *
     * @param non-empty-string $matcher
     * @param list<mixed>      $operands
     *
     * @return non-empty-list<mixed>
     */
    private static function operands(string $matcher, array $operands): array
    {
        if ($operands === []) {
            throw InvalidCallSpecification::emptyCombinator($matcher);
        }

        /** @var mixed $operand */
        foreach ($operands as $operand) {
            if ($operand instanceof TailMatcher) {
                throw InvalidCallSpecification::tailMatcherInCombinator($matcher, $operand->describe());
            }
        }

        return $operands;
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

    /**
     * "The arguments before this one matter, the rest of the arity does not."
     * Only valid as the last argument, and the one matcher that lets a
     * specification stop before the method's required parameters run out:
     *
     * ```php
     * when(fn () => $storage->recordOutcome('svc', Arg::rest()))
     *     ->throws(new RuntimeException('storage unavailable'));
     * ```
     *
     * The distinction from {@see remaining()}: `remaining()` stands for the
     * variadic tail a method declares; `rest()` stands for declared parameters
     * the specification chose not to spell out. A later, narrower
     * specification for the same call still wins over the broad prefix stub,
     * the way overlapping matchers already compose.
     */
    public static function rest(): mixed
    {
        return new AnyRest();
    }
}
