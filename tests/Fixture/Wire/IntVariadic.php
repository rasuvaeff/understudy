<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class IntVariadic
{
    /** @var list<int> */
    public readonly array $values;

    public function __construct(int ...$values)
    {
        $this->values = array_values($values);
    }
}
