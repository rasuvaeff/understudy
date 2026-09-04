<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture;

/**
 * A contract that returns the receiver, which is what `: static` means and
 * what makes a chain a chain.
 *
 * @internal
 */
interface FluentBuilder
{
    public function with(int $value): static;

    public function build(): string;
}
