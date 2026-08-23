<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Cls;

abstract class AbstractLedger
{
    abstract public function total(): int;

    public function twice(): int
    {
        return $this->total() * 2;
    }
}
