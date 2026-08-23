<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Def;

interface Chained
{
    public function next(): Chained;

    public function name(): string;
}
