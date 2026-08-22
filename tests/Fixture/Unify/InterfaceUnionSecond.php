<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface InterfaceUnionSecond
{
    public function narrow(): \Countable|string;

    public function wide(): \ArrayObject|string;
}
