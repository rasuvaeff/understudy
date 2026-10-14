<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

/**
 * `self` as a PARAMETER type. Written through, it would mean the generated
 * class — a subclass — and PHP 8.3/8.4 reject the override as narrowed.
 */
class SelfParameter
{
    public function mergeWith(self $other): string
    {
        return 'merged';
    }

    public function mergeMaybe(?self $other): string
    {
        return $other === null ? 'alone' : 'merged';
    }
}
