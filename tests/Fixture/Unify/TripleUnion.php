<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface TripleUnion
{
    public function pick(): IntersectionAlpha|IntersectionBeta|ReaderStringy;
}
