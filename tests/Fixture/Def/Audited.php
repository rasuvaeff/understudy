<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Def;

interface Audited extends Logger
{
    public function trail(): array;
}
