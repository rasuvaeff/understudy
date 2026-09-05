<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * A VALUE inside a specification that no run could act on: a maximum call
 * count below its minimum, a negative count, `returns()` with nothing to
 * return, a matcher range whose maximum sits below its minimum, a pattern
 * PCRE cannot compile, `Arg::instanceOf()` naming a type that is not
 * loadable.
 *
 * The line between this class and {@see InvalidCallSpecification}: that one
 * is about the SHAPE of a specification — what is called, where, how many
 * times — and this one about a number, a pattern or a name given to it. It
 * extends `\InvalidArgumentException` because that is the SPL type these
 * paths have always been, and a user's `catch (\InvalidArgumentException $e)`
 * around them keeps working; it implements `UnderstudyError` because that
 * interface is implemented by every exception this library throws.
 *
 * @api
 */
final class InvalidSpecificationArgument extends \InvalidArgumentException implements UnderstudyError
{
    /**
     * A cardinality whose upper bound is below its lower one: no number of
     * calls satisfies both.
     *
     * @param int $minimum the lower bound as written
     * @param int $maximum the upper bound as written, below the lower one
     */
    public static function maximumBelowMinimum(int $minimum, int $maximum): self
    {
        return new self(sprintf(
            'A maximum call count cannot be below the minimum, got minimum %d and maximum %d',
            $minimum,
            $maximum,
        ));
    }

    /**
     * A call count below zero, which no run can produce.
     *
     * @param int $count the count as written
     */
    public static function negativeCount(int $count): self
    {
        return new self('A call count cannot be negative, got ' . $count);
    }

    /**
     * `returns()` with no arguments: there is nothing for the double to
     * return, and the chain would answer the next call with nothing at all.
     */
    public static function noReturnValues(): self
    {
        return new self('returns() needs at least one value');
    }

    /**
     * `Arg::instanceOf()` naming a class or interface that is not loadable —
     * a matcher nothing can ever satisfy, which would otherwise report itself
     * only as an expectation that was never met.
     *
     * @param string $type the name as written
     */
    public static function unknownType(string $type): self
    {
        return new self(sprintf(
            'Arg::instanceOf(`%s`) can never match: no such class or interface is loadable.',
            $type,
        ));
    }

    /**
     * A range matcher whose maximum sits below its minimum, so it describes an
     * empty range and could never match.
     *
     * @param non-empty-string $matcher the factory that was called, without `Arg::`
     * @param int|float        $minimum the lower bound as it was given
     * @param int|float        $maximum the upper bound as it was given
     */
    public static function invertedBounds(string $matcher, int|float $minimum, int|float $maximum): self
    {
        return new self(sprintf(
            "`Arg::%s()` was given a minimum of %s and a maximum of %s, so it describes an empty "
            . "range and would match no argument at all.\n"
            . 'Order the bounds the other way, or leave one of them out to keep that side open.',
            $matcher,
            var_export($minimum, return: true),
            var_export($maximum, return: true),
        ));
    }

    /**
     * A pattern handed to `Arg::string()` that PCRE cannot compile.
     *
     * @param non-empty-string      $pattern the pattern as it was written
     * @param non-empty-string|null $reason  what PCRE said, when it said anything
     */
    public static function invalidPattern(string $pattern, ?string $reason): self
    {
        return new self(sprintf(
            "`Arg::string()` was given `%s`, which is not a valid PCRE pattern%s\n"
            . 'It would match no string and would raise a warning inside the code under test on '
            . 'every call. Check the delimiters and the escaping.',
            $pattern,
            $reason === null ? '.' : ': ' . $reason . '.',
        ));
    }
}
