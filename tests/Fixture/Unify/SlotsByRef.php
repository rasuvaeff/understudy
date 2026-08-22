<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface SlotsByRef
{
    public function &slots(): array;
}
