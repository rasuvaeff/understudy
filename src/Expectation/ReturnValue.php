<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Expectation;

use Rasuvaeff\Understudy\Invocation;

/**
 * @internal
 */
final readonly class ReturnValue implements Action
{
    public function __construct(private mixed $value) {}

    #[\Override]
    public function perform(Invocation $invocation): mixed
    {
        return $this->value;
    }
}
