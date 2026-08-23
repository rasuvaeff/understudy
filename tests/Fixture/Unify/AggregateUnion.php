<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface AggregateUnion
{
    public function pick(): \IteratorAggregate|string;
}
