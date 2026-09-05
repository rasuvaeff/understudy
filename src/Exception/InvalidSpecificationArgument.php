<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * An argument to a specification that no run could act on: a maximum call
 * count below its minimum, a negative count, `returns()` with nothing to
 * return.
 *
 * Extends `\InvalidArgumentException` because that is what these three paths
 * have always thrown, and a user's `catch (\InvalidArgumentException $e)`
 * around them must keep working. It implements `UnderstudyError` because that
 * interface says it is implemented by every exception this library throws,
 * and these three were the exceptions to that — so a `catch (UnderstudyError
 * $e)`, which the documentation recommends for catching misuse of Understudy
 * itself, walked straight past them.
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
}
