<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Cls;

abstract class PrivateConstantHolder
{
    protected const int STEP = 4;
}

/**
 * A default naming a constant the generated class cannot reach. Reflection
 * reports it as `self::STEP`, which would resolve against the double.
 */
abstract class PrivateConstantContract extends PrivateConstantHolder
{
    abstract public function step(int $n = self::STEP): int;
}
