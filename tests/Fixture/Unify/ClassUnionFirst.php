<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface ClassUnionFirst
{
    public function narrow(): \ArrayObject|int;

    public function wide(): \Countable|int;
}
