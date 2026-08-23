<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Perf\Support;

/**
 * A wide contract, so the cost of generating a double can be read against the
 * narrow one. Types are deliberately varied — nullable, union, array, void,
 * default argument — because a generator that only handles scalars is not the
 * generator any of these libraries ships.
 */
interface Ledger
{
    public function find(int $id): ?string;

    public function save(string $entry): void;

    public function entries(): array;

    public function count(): int;

    public function describe(): string|int;

    public function tag(string $name, int $weight = 1): string;

    public function rename(string $from, string $to): bool;

    public function purge(): void;
}
