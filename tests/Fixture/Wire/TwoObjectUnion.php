<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

class TwoObjectUnion
{
    public function __construct(public readonly Repository|Reporter $either) {}
}
