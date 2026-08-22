<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface FeederByValue
{
    public function feed(int $slot): void;
}
