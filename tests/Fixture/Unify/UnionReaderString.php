<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface UnionReaderString
{
    public function value(): ReaderInt|string;
}
