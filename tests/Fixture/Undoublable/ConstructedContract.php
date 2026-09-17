<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Undoublable;

interface ConstructedContract
{
    public function __construct(int $seed);

    public function next(): int;
}
