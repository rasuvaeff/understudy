<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture;

interface Clock
{
    public function now(): int;
}
