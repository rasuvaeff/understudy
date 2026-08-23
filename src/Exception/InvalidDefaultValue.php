<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * A registered default factory produced a value the contract cannot hold.
 *
 * @api
 */
final class InvalidDefaultValue extends \RuntimeException implements UnderstudyError
{
    /**
     * @param class-string $requested
     */
    public static function ofWrongType(string $requested, string $produced): self
    {
        return new self(sprintf(
            "The default factory registered for `%s` produced a `%s`.\n"
            . 'A factory has to return something the requested type can hold, or the double answers with a '
            . 'value the code under test cannot use.',
            $requested,
            $produced,
        ));
    }
}
