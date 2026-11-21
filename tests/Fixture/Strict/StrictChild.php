<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Strict;

use Rasuvaeff\Understudy\StrictStubs;

final class StrictChild extends StrictBase
{
    public function plain(): void {}

    #[StrictStubs(false)]
    public function relaxed(): void {}
}
