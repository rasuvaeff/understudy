<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface MixedWriter
{
    public function write(mixed $chunk): void;
}
