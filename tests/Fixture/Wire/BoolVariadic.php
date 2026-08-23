<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class BoolVariadic
{
    /** @var list<bool> */
    public readonly array $values;

    public function __construct(bool ...$values)
    {
        $this->values = array_values($values);
    }
}
