<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface NullableObjectParam
{
    public function accept(?ParentReturnBase $value): void;
}
