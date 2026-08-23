<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Fwd;

interface Chainable
{
    /** Returns the same instance, which is what makes a chain a chain. */
    public function withTag(string $tag): static;

    /** Returns a *different* instance of the same class. */
    public function detach(): static;

    /** The same thing without `static`: another instance is a legal answer. */
    public function spawn(): Chainable;

    public function label(): string;

    /** Calls `label()` on itself, so a proxy can be told from an instrumented object. */
    public function describe(): string;

    /** @return list<string> */
    public function seen(): array;
}
