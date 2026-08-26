<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * The closure handed to when()/verify()/calls() did not describe one call the
 * way a specification must: no direct call on an understudy, more than one, or
 * arguments that cannot form a valid specification.
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

    public static function notADouble(): self
    {
        return new self(
            'Understudy::forget() expects an understudy created by Understudy::for(). '
            . 'This object is not one.',
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
