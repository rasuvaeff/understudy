<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class FloatOrObjectUnion
{
    public function __construct(public readonly float|Repository $either) {}
}
