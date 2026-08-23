<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

/** Unrelated to {@see Wider}: neither satisfies the other, so both survive. */
interface Sibling
{
    public function shape(): Sibling;
}
