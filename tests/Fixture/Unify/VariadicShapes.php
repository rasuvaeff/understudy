<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface VariadicShapes
{
    public function byRefTail(int &...$slots): void;

    public function untypedTail(...$anything): void;

    public function intTail(int ...$numbers): void;
}
