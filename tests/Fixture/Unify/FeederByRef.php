<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface FeederByRef
{
    public function feed(int &$slot): void;
}
