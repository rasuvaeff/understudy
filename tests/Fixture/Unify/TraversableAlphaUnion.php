<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface TraversableAlphaUnion
{
    public function pick(): (IntersectionAlpha&\Traversable)|int;
}
