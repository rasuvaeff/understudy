<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Expectation;

use Rasuvaeff\Understudy\Invocation;

/**
 * @internal
 */
final readonly class ComputeAnswer implements Action
{
    /**
     * @param callable(Invocation): mixed $answer
     */
    public function __construct(private mixed $answer) {}

    #[\Override]
    public function perform(Invocation $invocation): mixed
    {
        return ($this->answer)($invocation);
    }
}
