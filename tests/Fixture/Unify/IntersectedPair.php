<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface IntersectedPair
{
    public function pick(): IntersectionAlpha&IntersectionBeta;
}
