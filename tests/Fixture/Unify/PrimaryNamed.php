<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface PrimaryNamed
{
    public function send(string $primary): void;
}
