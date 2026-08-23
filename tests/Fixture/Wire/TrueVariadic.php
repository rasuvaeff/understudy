<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class TrueVariadic
{
    /** @var list<true> */
    public readonly array $values;

    public function __construct(true ...$values)
    {
        $this->values = array_values($values);
    }
}
