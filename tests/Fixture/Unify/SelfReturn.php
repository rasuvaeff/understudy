<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface SelfReturn
{
    public function copy(): self;
}
