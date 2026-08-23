<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Bypass;

final readonly class SealedValue
{
    public function __construct(public int $amount = 0) {}

    public function describe(): string
    {
        return 'value';
    }
}
