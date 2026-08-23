<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Cls;

use Rasuvaeff\Understudy\Tests\Fixture\Suit;

/**
 * One method per shape a parameter default can take. Each is called without the
 * argument, so the double's default is compared against the contract's rather
 * than against a rendering of it.
 */
interface DefaultsContract
{
    public const string PREFIX = 'p-';

    public function withClassConstant(string $p = self::PREFIX): string;

    public function withEnumCase(Suit $s = Suit::Hearts): string;

    public function withGlobalConstant(int $n = PHP_INT_MAX): int;

    public function withArray(array $a = ['k' => self::PREFIX]): array;

    public function withNullableObject(?Stamp $s = null): bool;
}
