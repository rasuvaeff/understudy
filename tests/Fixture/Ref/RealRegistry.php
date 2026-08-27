<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Ref;

class RealRegistry implements Registry
{
    /** @var array<string, mixed> */
    private array $stored = ['seeded' => true];

    /** @var list<string> */
    private array $labels = [];

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    /** @return array<string, mixed> */
    #[\Override]
    public function &values(): array
    {
        return $this->stored;
    }

    /** @return list<string> */
    #[\Override]
    public function &names(): array
    {
        return $this->labels;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function &row(int $id): array
    {
        $this->rows[$id] ??= [];

        return $this->rows[$id];
    }

    #[\Override]
    public function fill(string &$slot, string $value): void
    {
        $slot = $value;
    }

    /** @param array<string, mixed> $rows */
    #[\Override]
    public function absorb(array &$rows): void
    {
        $rows['nested']['deep'] = 'written';
    }

    #[\Override]
    public function count(): int
    {
        return count($this->stored);
    }
}
