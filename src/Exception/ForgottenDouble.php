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
     * @param non-empty-string $method
     */
    public static function onPurpose(string $method): self
    {
        return new self(sprintf(
            "This understudy was retired with Understudy::forget(), but `%s()` was called on it.\n"
            . 'A forgotten double is gone for good; build a new one instead of calling this one.',
            $method,
        ));
    }

    /**
     * @param non-empty-string $property
     */
    public static function propertyAfterReset(string $property): self
    {
        return new self(sprintf(
            "This understudy is no longer known to Understudy, but its property `\$%s` was touched.\n"
            . 'It was created before a reset(); create doubles inside the test that uses them '
            . 'rather than sharing one across tests.',
            $property,
        ));
    }

    public static function retired(): self
    {
        return new self(
            'This understudy was retired with Understudy::forget() and can no longer be asked '
            . 'anything — not calls, not verification. A replacement built afterwards is a '
            . 'different object; ask that one.',
        );
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
