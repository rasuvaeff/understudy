<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface DnfParam
{
    public function store((ReaderInt&ReaderStringy)|null $slot): void;
}
