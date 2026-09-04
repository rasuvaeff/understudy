<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface TaggerFiveToo
{
    public function tag(string $name, int $weight = 5): void;
}
