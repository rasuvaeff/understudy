<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface IntersectionBeta
{
    public function intersected(): IntersectionBeta;

    public function nullableIntersection(): ?IntersectionBeta;
}
