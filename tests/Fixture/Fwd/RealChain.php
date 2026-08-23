<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Fwd;

class RealChain implements Chainable
{
    /** @var list<string> */
    private array $tags = [];

    #[\Override]
    public function withTag(string $tag): static
    {
        $this->tags[] = $tag;

        return $this;
    }

    #[\Override]
    public function detach(): static
    {
        return new static();
    }

    #[\Override]
    public function spawn(): Chainable
    {
        return new static();
    }

    #[\Override]
    public function label(): string
    {
        return 'real';
    }

    #[\Override]
    public function describe(): string
    {
        return 'described by ' . $this->label();
    }

    /** @return list<string> */
    #[\Override]
    public function seen(): array
    {
        return $this->tags;
    }
}
