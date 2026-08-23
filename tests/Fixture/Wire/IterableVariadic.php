<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class IterableVariadic
{
    /** @var list<iterable<mixed, mixed>> */
    public readonly array $values;

    public function __construct(iterable ...$values)
    {
        $this->values = array_values($values);
    }
}
