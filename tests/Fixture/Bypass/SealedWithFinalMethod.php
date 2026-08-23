<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Bypass;

final class SealedWithFinalMethod
{
    final public function locked(): string
    {
        return 'locked';
    }
}
