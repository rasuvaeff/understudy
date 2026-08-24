<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface WiderObjectParam
{
    public function accept(ParentReturnBase|string|null $value): void;
}
