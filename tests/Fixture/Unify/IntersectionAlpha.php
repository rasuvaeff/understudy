<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface IntersectionAlpha
{
    public function intersected(): IntersectionAlpha;

    public function nullableIntersection(): ?IntersectionAlpha;
}
