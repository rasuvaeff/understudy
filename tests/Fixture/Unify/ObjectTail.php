<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface ObjectTail
{
    public function sink(object ...$items): void;
}
