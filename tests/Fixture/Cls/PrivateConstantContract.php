<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Cls;

/**
 * A default naming a constant the generated class cannot reach. Reflection
 * reports it as `self::STEP`, and `self` inside the double is a different
 * class — a subclass, which a *protected* constant would still reach. Private
 * is what makes the fallback the only way to answer correctly.
 */
abstract class PrivateConstantContract
{
    private const int STEP = 4;

    abstract public function step(int $n = self::STEP): int;
}
