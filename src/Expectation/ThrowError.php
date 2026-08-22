<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Expectation;

use Rasuvaeff\Understudy\Invocation;

/**
 * @internal
 */
final readonly class ThrowError implements Action
{
    public function __construct(private \Throwable $error) {}

    #[\Override]
    public function perform(Invocation $invocation): mixed
    {
        throw $this->error;
    }
}
