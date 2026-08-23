<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface StaticReferenceReturnContract
{
    public static function &ping(): array;
}
