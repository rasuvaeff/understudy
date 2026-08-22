<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface MixedTail
{
    public function sink(mixed ...$items): void;
}
