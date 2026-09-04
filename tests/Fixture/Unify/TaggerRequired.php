<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface TaggerRequired
{
    public function tag(string $name, int $weight): void;
}
