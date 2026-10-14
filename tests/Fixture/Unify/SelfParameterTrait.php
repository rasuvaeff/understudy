<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

/**
 * Reflection reports `self` from a trait literally on every version, 8.5
 * included, so this is the shape a local 8.5 run still catches.
 */
trait SelfParameterTrait
{
    public function mergeWith(self $other): string
    {
        return 'merged';
    }
}
