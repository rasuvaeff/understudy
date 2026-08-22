<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface WriterString
{
    public function write(string $chunk): void;
}
