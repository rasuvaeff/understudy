<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * A method declared `: never` was called without an expectation that throws.
 * Returning from it is impossible by language rule, so the dispatcher must
 * throw something — this says so explicitly instead of leaking a TypeError.
 *
 * @api
 */
final class NeverMethodCalled extends \RuntimeException implements UnderstudyError
{
    /**
     * @param non-empty-string $label
     * @param non-empty-string $method
     */
    public static function withoutExpectation(string $label, string $method): self
    {
        return new self(sprintf(
            "Understudy `%s` received a call to `%s()`, which is declared `: never` and cannot return.\n"
            . 'Configure what it throws: when(fn () => $double->%s(...))->throws(new SomeException())',
            $label,
            $method,
            $method,
        ));
    }

    /**
     * @param non-empty-string $label
     * @param non-empty-string $method
     */
    public static function configuredToReturn(string $label, string $method): self
    {
        return new self(sprintf(
            "Understudy `%s` has `%s()` configured to return, but the method is declared `: never` and cannot.\n"
            . 'Configure it to throw instead: when(fn () => $double->%s(...))->throws(new SomeException())',
            $label,
            $method,
            $method,
        ));
    }
}
