<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Def;

interface Logger
{
    public function log(string $message): void;
}
