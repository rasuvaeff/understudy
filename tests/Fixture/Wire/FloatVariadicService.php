<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class FloatVariadicService
{
    /** @var list<float> */
    public readonly array $rates;

    public function __construct(
        public readonly Repository $repository,
        float ...$rates,
    ) {
        $this->rates = array_values($rates);
    }
}
