<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface NarrowPartsUnion
{
    public function pick(): (BothIterableCountable&\JsonSerializable)|string;
}
