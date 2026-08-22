<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Expectation;

use Rasuvaeff\Understudy\Invocation;

/**
 * What a matched expectation does: return a value, throw, or compute an answer.
 *
 * @internal
 */
interface Action
{
    public function perform(Invocation $invocation): mixed;
}
