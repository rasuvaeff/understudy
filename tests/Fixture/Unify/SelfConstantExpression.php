<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

/**
 * The default is an EXPRESSION mentioning `self::`, not a bare constant, so
 * Reflection reports no constant name and the source has to be read — where
 * `self` would resolve against the generated class instead of this one.
 */
interface SelfConstantExpression
{
    public const int STEP = 3;

    public function step(int $size = self::STEP * 2): int;
}
