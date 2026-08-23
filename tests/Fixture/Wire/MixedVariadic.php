<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class MixedVariadic
{
    /** @var list<mixed> */
    public readonly array $values;

    public function __construct(mixed ...$values)
    {
        $this->values = array_values($values);
    }
}
