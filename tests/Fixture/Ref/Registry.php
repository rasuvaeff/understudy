<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Ref;

interface Registry
{
    /** @return array<string, mixed> */
    public function &values(): array;

    /** @return list<string> */
    public function &names(): array;

    /** @return array<string, mixed> */
    public function &row(int $id): array;

    public function fill(string &$slot, string $value): void;

    /**
     * An array argument taken by reference, whose rows may themselves be
     * references — which is where a top-level-only snapshot leaks.
     *
     * @param array<string, mixed> $rows
     */
    public function absorb(array &$rows): void;

    public function count(): int;
}
