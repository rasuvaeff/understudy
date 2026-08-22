<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface WriterIntToo
{
    public function write(int $chunk): void;
}
