<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Defaults;

use Rasuvaeff\Understudy\Tests\Fixture\Suit;

interface EnumInArray
{
    /** @param array<string, Suit> $hands */
    public function deal(array $hands = ['first' => Suit::Hearts]): int;
}
