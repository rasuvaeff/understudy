<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

/**
 * `mixed` with a `null` default: the one shape whose rendered union PHP
 * refuses outright, since `mixed` may not be part of one.
 */
interface MixedNullDefault
{
    public function accept(mixed $value = null): void;
}
