<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * The closure handed to when()/verify()/calls() did not describe one call the
 * way a specification must: no direct call on an understudy, more than one, or
 * arguments that cannot form a valid specification — which includes a matcher
 * configured so that it could never match anything.
 *
 * @api
 */
final class InvalidCallSpecification extends \LogicException implements UnderstudyError
{
    public static function noCallRecorded(): self
    {
        return new self(
            'The specification closure did not call a method on an understudy. '
            . 'It must contain exactly one direct call, for example: '
            . 'when(fn () => $repository->find(123))',
        );
    }

    /**
     * Handed the object directly, so there is no closure to blame — naming
     * one sends the reader looking for a mistake they have not made.
     *
     * @param non-empty-string $facade the method that was given the object
     */
    public static function notADouble(string $facade): self
    {
        return new self(sprintf(
            'Understudy::%s() expects an understudy created by Understudy::for(). '
            . 'This object is not one.',
            $facade,
        ));
    }

    /**
     * The wording is the analysers' word for word, and deliberately so: a
     * user who saw the report before running the suite must not have to
     * recognise a second phrasing of the same mistake afterwards.
     *
     * @param non-empty-string $bound the count argument written beside `never`
     */
    public static function neverBesideACount(string $bound): self
    {
        return new self(sprintf(
            '`never: true` says the call never happened, and `%s` says how often it did. '
            . 'Keep the one you mean.',
            $bound,
        ));
    }

    public static function exactCountBesideABound(): self
    {
        return new self(
            '`times` is an exact count, so a `minimum` or `maximum` beside it has nothing left '
            . 'to constrain. Use one or the other.',
        );
    }

    /**
     * @param non-empty-string $method
     * @param non-empty-string $matcher
     */
    public static function misplacedTailMatcher(string $method, int $position, string $matcher): self
    {
        return new self(sprintf(
            "`%s` stands for the whole variadic tail, so it may only be the last argument, "
            . "but it was given as argument #%d of `%s()`.\n"
            . 'Move it to the end, or use Arg::any() to match that one argument.',
            $matcher,
            $position + 1,
            $method,
        ));
    }

    /**
     * @param non-empty-string $matcher
     */
    public static function emptyCombinator(string $matcher): self
    {
        return new self(sprintf(
            "`Arg::%s()` was given no operands, so it would match %s.\n"
            . 'Pass what the argument has to satisfy, or use Arg::any() to accept anything.',
            $matcher,
            $matcher === 'allOf' ? 'every argument' : 'no argument at all',
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

    /**
     * @param non-empty-string $matcher
     * @param non-empty-string $operand
     */
    public static function tailMatcherInCombinator(string $matcher, string $operand): self
    {
        return new self(sprintf(
            "`%s` stands for the whole variadic tail, and `Arg::%s()` combines matchers for one "
            . "argument, so it cannot hold one.\n"
            . 'Put the tail matcher last on its own, and combine the arguments before it.',
            $operand,
            $matcher,
        ));
    }

    public static function emptySequence(): self
    {
        return new self(
            'Understudy::expectSequence() needs at least one call: an empty protocol claims nothing '
            . 'and would refuse every call on no double at all',
        );
    }

    /**
     * @param positive-int $position
     * @param positive-int $length
     */
    public static function protocolAlreadyArmed(int $position, int $length): self
    {
        return new self(sprintf(
            'A protocol is already armed and is waiting on step %d of %d. Two of them naming the same '
            . 'understudy would each judge every call on it and could disagree about the same call — '
            . 'finish this one, or describe both phases as a single protocol',
            $position,
            $length,
        ));
    }

    /**
     * @param non-empty-string $method
     * @param int<0, max>      $given
     * @param int<0, max>      $declared
     */
    public static function incompleteSpecification(string $method, int $given, int $declared): self
    {
        return new self(sprintf(
            "The specification for `%s()` passed %d of its %d arguments.\n"
            . 'Spell every argument, or say the rest does not matter by ending with Arg::rest().',
            $method,
            $given,
            $declared,
        ));
    }

    /**
     * @param non-empty-string $method
     * @param int<0, max>      $omitted
     * @param int<0, max>      $specified
     */
    public static function omittedBeforeSpecified(string $method, int $omitted, int $specified): self
    {
        return new self(sprintf(
            "The specification for `%s()` omitted argument #%d but specified argument #%d after it.\n"
            . 'A specification spells its arguments in order — use Arg::any() for one that does not matter.',
            $method,
            $omitted + 1,
            $specified + 1,
        ));
    }

    /**
     * @param non-empty-string $method
     * @param non-empty-string $matcher
     */
    public static function omittedTailNeedsRest(string $method, string $matcher): self
    {
        return new self(sprintf(
            "`%s` describes a variadic tail, not parameters left unspelled, and the specification "
            . "for `%s()` stopped before its required parameters ran out.\n"
            . 'End with Arg::rest() to say the remaining parameters do not matter.',
            $matcher,
            $method,
        ));
    }

    public static function closureFailed(\Throwable $previous): self
    {
        return new self(
            'The specification closure threw before it reached an understudy: '
            . $previous->getMessage(),
            previous: $previous,
        );
    }

    /**
     * @param non-empty-string $method
     */
    public static function staticMethodCalled(string $method): self
    {
        return new self(sprintf(
            "Static method `%s()` cannot be called on an understudy because static calls have no instance state.\n"
            . 'Inject an instance dependency and double that contract instead.',
            $method,
        ));
    }
}
