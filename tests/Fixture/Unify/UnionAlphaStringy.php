<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface UnionAlphaStringy
{
    public function pick(): IntersectionAlpha|ReaderStringy;
}
