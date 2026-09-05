<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * The requested target cannot be doubled, and no option would make it work
 * without the user changing something. The message names that something.
 *
 * @api
 */
final class UnsupportedTarget extends \LogicException implements UnderstudyError
{
    /**
     * No class or interface of that name is loadable — usually a typo, or a
     * class the autoloader cannot see from the test.
     */
    public static function missing(string $target): self
    {
        return new self(sprintf(
            'Cannot create an understudy for `%s`: no such class or interface is loadable.',
            $target,
        ));
    }

    /**
     * Builds the error for a target that cannot be doubled.
     *
     * @param non-empty-string $target
     */
    public static function notDoublable(string $target, string $reason): self
    {
        return new self(sprintf(
            'Cannot create an understudy for `%s`: %s',
            $target,
            $reason,
        ));
    }

    /**
     * Builds the error for incompatible target signatures.
     *
     * @param non-empty-string $method
     */
    public static function signatureConflict(string $method, string $left, string $right): self
    {
        return new self(sprintf(
            "Cannot create one understudy for these targets: method `%s()` has no implementation that satisfies all of them.\n  %s\n  %s",
            $method,
            $left,
            $right,
        ));
    }
}
