<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface VariadicByValueTail
{
    public function byRefTail(int ...$slots): void;
}
