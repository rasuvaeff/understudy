<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Bypass;

final class SealedGate
{
    public function allows(string $action): bool
    {
        return false;
    }
}
