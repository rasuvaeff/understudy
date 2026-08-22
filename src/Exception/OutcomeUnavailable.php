<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * An invocation's outcome was read as the wrong kind: a returned value asked
 * of a call that threw. `null` is a valid return value, so this cannot be
 * signalled by returning null.
 *
 * @api
 */
final class OutcomeUnavailable extends \LogicException implements UnderstudyError
{
    /**
     * @param non-empty-string $method
     */
    public static function threwInstead(string $method, \Throwable $thrown): self
    {
        return new self(sprintf(
            'Call to `%s()` threw %s and has no return value. Check didReturn() first, or read thrown().',
            $method,
            $thrown::class,
        ));
    }
}
