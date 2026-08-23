<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * A double outlived the context that created it — almost always a double kept
 * in a static property or a shared fixture across `Understudy::reset()`.
 *
 * Answering with `null` instead would violate the declared return type of any
 * non-nullable method and surface far from the actual mistake.
 *
 * @api
 */
final class ForgottenDouble extends \LogicException implements UnderstudyError
{
    /**
     * @param non-empty-string $method
     */
    public static function afterReset(string $method): self
    {
        return new self(sprintf(
            "This understudy is no longer known to Understudy, but `%s()` was called on it.\n"
            . 'It was created before a reset(); create doubles inside the test that uses them '
            . 'rather than sharing one across tests.',
            $method,
        ));
    }

    /**
     * @param class-string $contract
     */
    public static function fromDefaultFactory(string $contract): self
    {
        return new self(sprintf(
            "The default factory for `%s` answered with an understudy that is no longer known to Understudy.\n"
            . 'It was created before a reset(); register the factory inside the test that uses it '
            . 'rather than sharing one double across tests.',
            $contract,
        ));
    }
}
