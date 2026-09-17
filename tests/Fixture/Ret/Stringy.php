<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Ret;

final class Stringy implements \Stringable
{
    #[\Override]
    public function __toString(): string
    {
        return 'x';
    }
}
