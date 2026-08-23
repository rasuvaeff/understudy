<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Fwd;

interface Filler
{
    public function fill(string &$slot, string $value): void;

    public function collapse(): never;
}
