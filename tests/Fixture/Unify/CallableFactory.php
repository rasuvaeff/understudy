<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface CallableFactory
{
    public function factory(): callable;
}
