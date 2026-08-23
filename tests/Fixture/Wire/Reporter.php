<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

interface Reporter
{
    public function report(string $line): void;
}
