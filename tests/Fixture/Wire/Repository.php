<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Wire;

interface Repository
{
    public function find(int $id): string;
}
