<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface WidePartsUnion
{
    public function pick(): (\Traversable&\Countable&\Stringable)|int;
}
