<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface UnionReaderInt
{
    public function value(): ReaderInt|int;
}
