<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Cls;

readonly class ReadonlyLedger
{
    public function __construct(public string $name = 'ledger') {}

    public function describe(): string
    {
        return $this->name;
    }
}
