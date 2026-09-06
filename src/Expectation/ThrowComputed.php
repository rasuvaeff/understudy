<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Expectation;

use Rasuvaeff\Understudy\Invocation;

/**
 * Throws an exception built from the call that triggered it.
 *
 * Separate from {@see ThrowError}, which throws one instance however often the
 * link answers: an exception carrying the argument of the call it answers has
 * to be built per call.
 *
 * @internal
 */
final readonly class ThrowComputed implements Action
{
    /**
     * @param callable(Invocation): \Throwable $build
     */
    public function __construct(private mixed $build) {}

    #[\Override]
    public function perform(Invocation $invocation): mixed
    {
        throw ($this->build)($invocation);
    }
}
