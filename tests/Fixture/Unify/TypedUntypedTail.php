<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface TypedUntypedTail
{
    public function untypedTail(int ...$anything): void;
}
