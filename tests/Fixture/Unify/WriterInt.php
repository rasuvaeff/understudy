<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface WriterInt
{
    public static function describe(): string;

    public function write(int $chunk): void;
}
