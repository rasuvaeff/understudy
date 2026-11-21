<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Strict;

use Rasuvaeff\Understudy\StrictStubs;

#[StrictStubs]
abstract class StrictBase
{
    public function inherited(): void {}
}
