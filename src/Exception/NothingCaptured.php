<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * `Captor::last()` was read before any matched call carried a value through
 * its `capture()` argument.
 *
 * @api
 */
final class NothingCaptured extends \LogicException implements UnderstudyError
{
    /**
     * @param class-string|null $class
     */
    public static function forCaptor(?string $class): self
    {
        return new self(sprintf(
            "The captor%s has captured nothing: no matched call carried a value through its capture() argument.\n"
            . 'Make the call happen first — or read all(), which answers an empty list.',
            $class === null ? '' : sprintf(' for `%s`', $class),
        ));
    }
}
