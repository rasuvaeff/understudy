<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Strict;

use Rasuvaeff\Understudy\StrictStubs;

#[StrictStubs(false)]
final class RelaxedChild extends StrictBase
{
    public function plain(): void {}

    #[StrictStubs]
    public function strict(): void {}
}
