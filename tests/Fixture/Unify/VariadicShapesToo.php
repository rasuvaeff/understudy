<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface VariadicShapesToo
{
    public function intTail(string ...$words): void;
}
