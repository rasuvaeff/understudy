<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class FalseVariadic
{
    /** @var list<false> */
    public readonly array $values;

    public function __construct(false ...$values)
    {
        $this->values = array_values($values);
    }
}
