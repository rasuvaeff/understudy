<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface SelfParameterContract
{
    public function mergeWith(self $other): string;
}
