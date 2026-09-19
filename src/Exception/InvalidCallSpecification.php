<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * A specification of the wrong SHAPE: the closure handed to when()/verify()/
 * calls() made no direct call on an understudy, made it on a static method or
 * threw first; a tail matcher was not the last argument; the specification
 * stopped before the required parameters ran out without saying so with
 * `Arg::rest()`; a combinator was given nothing to combine; `verify()` was
 * handed arguments that contradict each other; a facade was handed an object
 * that is not a double; a protocol was armed over one still running.
 *
 * The line between this class and {@see InvalidSpecificationArgument}: this
 * one is about how a specification is written — what is called, where, how
 * many times — and that one about a VALUE inside it that no run could act on
 * (an inverted range, a count below zero, a pattern PCRE cannot compile, a
 * type that is not loadable). A `LogicException`, because the test is
 * malformed rather than given a bad number.
 *
 * @api
 */
final class InvalidCallSpecification extends \LogicException implements UnderstudyError
{
    /**
     * The closure ran to its end without any generated method signalling: it
     * called nothing on a double, or only something that is not one.
     */
    public static function noCallRecorded(): self
    {
        return new self(
            'The specification closure did not call a method on an understudy. '
            . 'It must contain exactly one direct call, for example: '
            . 'when(fn () => $repository->find(123))',
        );
    }

    /**
     * The closure called more than one double method. Nested in the arguments
     * of another (`find($r->count())`) or one after the other (`a() + b()`),
     * the recording cannot tell which one the test meant, and neither reading
     * is safe to guess.
     *
     * @param non-empty-string $firstLabel  the double the first call was made on
     * @param non-empty-string $firstMethod the first call dispatched — for a nested pair, the inner one
     * @param non-empty-string $lastLabel   the double the last call was made on
     * @param non-empty-string $lastMethod  the last call dispatched — for a nested pair, the outer one
     */
    public static function moreThanOneCall(string $firstLabel, string $firstMethod, string $lastLabel, string $lastMethod): self
    {
        return new self(sprintf(
            'The specification closure calls `%s()` on understudy `%s` and then `%s()` on understudy `%s`. '
            . 'A closure must contain exactly one direct call on a double; with two, one of them would be specified '
            . 'silently and the other not at all. Stub each call in a when() of its own, and where one call feeds the '
            . 'arguments of another pass a literal or a matcher instead, for example: when(fn () => $repository->find(Arg::any()))',
            $firstMethod,
            $firstLabel,
            $lastMethod,
            $lastLabel,
        ));
    }

    /**
     * `returns()` on a method declared `: void`: the value would never be
     * observed, and a test that relies on it is checking nothing.
     *
     * @param non-empty-string $label  the double, as a refusal names it
     * @param non-empty-string $method the method declared `: void`
     */
    public static function returnsOnVoid(string $label, string $method): self
    {
        return new self(sprintf(
            'Understudy `%s`: `%s()` is declared `: void`, so returns() has nothing to answer with — the value '
            . 'would never be observed. Drop the value (returns(null) is allowed), or use answers()/throws() if the '
            . 'call should do something.',
            $label,
            $method,
        ));
    }

    /**
     * `returns()` on a method declared `: never`, which cannot return at all.
     *
     * @param non-empty-string $label  the double, as a refusal names it
     * @param non-empty-string $method the method declared `: never`
     */
    public static function returnsOnNever(string $label, string $method): self
    {
        return new self(sprintf(
            'Understudy `%s`: `%s()` is declared `: never` and cannot return. Configure what it throws: '
            . 'when(fn () => $double->%s(...))->throws(new SomeException())',
            $label,
            $method,
            $method,
        ));
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

    /**
     * `verify($call, times: 2, minimum: 1)`: an exact count leaves a bound
     * nothing to constrain, so one of the two is a mistake.
     */
    public static function exactCountBesideABound(): self
    {
        return new self(
            '`times` is an exact count, so a `minimum` or `maximum` beside it has nothing left '
            . 'to constrain. Use one or the other.',
        );
    }

    /**
     * Builds the error for a tail matcher in the wrong position.
     *
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
     * Builds the error for an empty matcher combinator.
     *
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
     * Builds the error for putting a tail matcher inside a combinator.
     *
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

    /**
     * Builds the error for a matcher nested inside an array argument.
     *
     * @param non-empty-string $method   the method the specification named
     * @param int              $position  zero-based position of the array argument
     * @param non-empty-string $matcher   how the buried matcher describes itself
     */
    public static function matcherInsideArray(string $method, int $position, string $matcher): self
    {
        return new self(sprintf(
            "`%s` sits inside the array given as argument #%d of `%s()`, where it is compared by "
            . "identity and can never match.\n"
            . 'Describe the array with Arg::containing([...]), which reads matchers in its entries, '
            . 'or the whole argument with Arg::satisfies().',
            $matcher,
            $position + 1,
            $method,
        ));
    }

    /**
     * Builds the error for a captor inside a combinator.
     *
     * @param non-empty-string $matcher the combinator the captor was passed to, without `Arg::`
     */
    public static function captorInCombinator(string $matcher): self
    {
        return new self(sprintf(
            "A captor records the argument it stands for, and `Arg::%s()` builds one matcher out of "
            . "others, so a captor inside it would match without ever recording.\n"
            . 'Put the captor in the argument position itself, or read the calls with '
            . 'Understudy::calls().',
            $matcher,
        ));
    }

    /**
     * `expectSequence()` with no steps: arming an empty protocol would put every
     * later call on trial with nothing to try it against.
     */
    public static function emptySequence(): self
    {
        return new self(
            'Understudy::expectSequence() needs at least one call: an empty protocol claims nothing '
            . 'and would refuse every call on no double at all',
        );
    }

    /**
     * Builds the error for arming a protocol while another is active.
     *
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
     * Builds the error for an incomplete call specification.
     *
     * @param non-empty-string $method
     * @param int<0, max>      $given
     * @param int<0, max>      $declared
     */
    public static function incompleteSpecification(string $method, int $given, int $declared): self
    {
        return new self(sprintf(
            "The specification for `%s()` passed %d of its %d arguments, and the ones it left out "
            . "are not all optional.\n"
            . 'Spell every required argument, or say the rest does not matter by ending with Arg::rest().',
            $method,
            $given,
            $declared,
        ));
    }

    /**
     * Builds the error for an omitted argument before a specified one.
     *
     * @param non-empty-string $method
     * @param int<0, max>      $omitted
     * @param int<0, max>      $specified
     */
    public static function omittedBeforeSpecified(string $method, int $omitted, int $specified): self
    {
        return new self(sprintf(
            "The specification for `%s()` omitted argument #%d — which the contract declares required — "
            . "but specified argument #%d after it.\n"
            . 'A specification spells its required arguments in order — use Arg::any() for one that does not matter.',
            $method,
            $omitted + 1,
            $specified + 1,
        ));
    }

    /**
     * Builds the error for an omitted required tail without `Arg::rest()`.
     *
     * @param non-empty-string $method
     * @param non-empty-string $matcher
     */
    public static function omittedTailNeedsRest(string $method, string $matcher): self
    {
        return new self(sprintf(
            "`%s` describes a variadic tail, not parameters left unspelled, and the specification "
            . "for `%s()` stopped before its parameters ran out.\n"
            . 'End with Arg::rest() to say the remaining parameters do not matter.',
            $matcher,
            $method,
        ));
    }

    /**
     * The specification closure threw before any generated method signalled;
     * the original is kept as `previous`, because it is the actual mistake.
     */
    public static function closureFailed(\Throwable $previous): self
    {
        return new self(
            'The specification closure threw before it reached an understudy: '
            . $previous->getMessage(),
            previous: $previous,
        );
    }

    /**
     * Builds the error for a static method used in a specification.
     *
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
