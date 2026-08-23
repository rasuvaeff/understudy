<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Cls;

/**
 * Every property shape the initializer has to decide about, in one class used
 * by one test. Sharing {@see Ledger} would have hidden the decisions: a
 * blueprint is compiled once per contract, so whichever test ran first would be
 * the only one Infection credits with running the initializer.
 *
 * The order is deliberate. Each property the initializer must skip is declared
 * *before* one it must fill, so a `continue` that became a `break` would drop a
 * value the test asserts instead of ending a loop with nothing left to do.
 */
class PropertyLedger
{
    public string $declared = 'kept';

    public readonly string $sealed;

    public static int $shared;

    public \DateTimeImmutable $stamp;

    public $untyped;

    protected int $hidden = 0;

    public int $count;

    public float $rate;

    public bool $open;

    public string $note;

    public array $rows;

    public iterable $stream;

    public ?PropertyLedger $parent;

    public mixed $anything;

    public function __construct()
    {
        throw new \LogicException('the target constructor ran');
    }
}
