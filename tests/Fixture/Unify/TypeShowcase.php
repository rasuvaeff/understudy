<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Unify;

interface TypeShowcase
{
    public function scalar(int $a): void;

    public function nullable(?int $a): void;

    public function union(int|string $a): void;

    public function anything(mixed $a): void;

    public function anyObject(object $a): void;

    public function untyped($a): void;

    public function intersection(ReaderInt&ReaderStringy $a): void;

    public function objectParam(TypeShowcase $a): void;

    public function nullableObject(?TypeShowcase $a): void;

    public function returnsNullableObject(): ?TypeShowcase;

    public function dnf((ReaderInt&ReaderStringy)|null $a): void;

    public function returnsDnf(): (ReaderInt&ReaderStringy)|null;

    public function returnsNever(): never;
}
