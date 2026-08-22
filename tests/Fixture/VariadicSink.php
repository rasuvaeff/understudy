<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture;

interface VariadicSink
{
    public function write(string $channel, int ...$values): bool;
}
