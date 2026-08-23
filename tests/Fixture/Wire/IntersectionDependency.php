<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class IntersectionDependency
{
    public function __construct(public readonly Repository&\Countable $both) {}
}
