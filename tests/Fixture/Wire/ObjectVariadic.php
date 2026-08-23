<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class ObjectVariadic
{
    /** @var list<object> */
    public readonly array $values;

    public function __construct(object ...$values)
    {
        $this->values = array_values($values);
    }
}
