<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class ScalarWithoutDefault
{
    public function __construct(public readonly string $name) {}
}
