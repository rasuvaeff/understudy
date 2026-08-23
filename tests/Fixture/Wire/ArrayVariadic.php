<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class ArrayVariadic
{
    /** @var list<array<array-key, mixed>> */
    public readonly array $values;

    public function __construct(array ...$values)
    {
        $this->values = array_values($values);
    }
}
