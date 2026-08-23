<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

/** Extends {@see Wider}, so `Narrower` alone already satisfies both. */
interface Narrower extends Wider
{
    public function shape(): Narrower;
}
