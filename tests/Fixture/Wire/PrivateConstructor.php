<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class PrivateConstructor
{
    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }
}
