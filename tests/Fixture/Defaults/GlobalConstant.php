<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Defaults;

/**
 * Reflection reports this as `Rasuvaeff\…\PHP_EOL` — a name that does not
 * exist. Only the value can be rendered.
 */
interface GlobalConstant
{
    public function sized(int $max = PHP_INT_MAX): int;
}
