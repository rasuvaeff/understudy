<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface IntTailPlain
{
    public function sink(int ...$items): void;
}
