<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface ArityOne
{
    public function emit(int $a): void;

    public function emitWithDefault(string $name = 'fallback'): void;
}
