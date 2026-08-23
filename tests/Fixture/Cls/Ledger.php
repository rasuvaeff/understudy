<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Cls;

/**
 * The class target the codegen tests build on. Every member is here to be
 * observed: the constructor and destructor throw, so a double that ran either
 * of them fails loudly rather than subtly.
 */
class Ledger
{
    public string $named = 'declared';

    public int $count;

    public array $rows;

    public ?Ledger $parent;

    public \DateTimeImmutable $stamp;

    public function __construct(string $named)
    {
        $this->named = $named;

        throw new \LogicException('the target constructor ran');
    }

    public function record(string $entry, int $weight = 1): int
    {
        return -1;
    }

    public function describe(): string
    {
        return 'the real ledger';
    }

    protected function audit(string $entry): string
    {
        return 'audited ' . $entry;
    }

    private function secret(): string
    {
        return 'private';
    }

    public static function version(): string
    {
        return '1.0';
    }

    public function __destruct()
    {
        throw new \LogicException('the target destructor ran');
    }
}
