<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Ref;

interface Registry
{
    /** @return array<string, mixed> */
    public function &values(): array;

    /** @return list<string> */
    public function &names(): array;

    public function fill(string &$slot, string $value): void;

    public function count(): int;
}
