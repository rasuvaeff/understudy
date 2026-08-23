<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class CallableVariadic
{
    /** @var list<callable> */
    public readonly array $values;

    public function __construct(callable ...$values)
    {
        $this->values = array_values($values);
    }
}
