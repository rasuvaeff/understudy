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

    public static function closureFailed(\Throwable $previous): self
    {
        return new self(
            'The specification closure threw before it reached an understudy: '
            . $previous->getMessage(),
            previous: $previous,
        );
    }
}
