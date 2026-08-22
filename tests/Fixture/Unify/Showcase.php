<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface Showcase
{
    public function noParams(): int;

    public function scalar(int $a): void;

    public function nullable(?string $a): void;

    public function withDefault(int $a = 7): void;

    public function withNullDefault(?int $a = null): void;

    public function variadic(string ...$rest): void;

    public function scalarThenVariadic(int $first, string ...$rest): void;

    public function byReference(array &$slot): void;

    public function untyped($a): void;

    public function intersection(ReaderInt&ReaderStringy $a): void;

    public function anything(mixed $a): void;

    public function anyObject(object $a): void;

    public function &returnsReference(): array;

    public function goesAway(): never;
}
