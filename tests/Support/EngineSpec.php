<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Support;

/**
 * One configured call in the model behind
 * {@see \Rasuvaeff\Understudy\Tests\EngineLifecyclePropertyTest}: a `find()`
 * specification with a literal-or-wildcard argument, registered either as a
 * stub (`when()`, permission only) or as a claim (`expect()`, a cardinality
 * promise).
 *
 * The model tracks what the engine's ledger tracks: how often this
 * specification matched, and whether those matches stay inside the promised
 * bounds. Everything else — chains, ordered claims, matchers richer than a
 * literal or a wildcard — is deliberately out of scope; it is pinned by the
 * unit suites.
 */
final readonly class EngineSpec
{
    public function __construct(
        public bool $anyArgument,
        public int $literalId,
        public bool $isClaim,
        public string $answerTitle,
        public int $minimum = 0,
        public ?int $maximum = null,
        public int $matched = 0,
    ) {}

    public function accepts(int $id): bool
    {
        return $this->anyArgument || $this->literalId === $id;
    }

    public function hasAction(): bool
    {
        return !$this->isClaim;
    }

    public function withinCardinality(): bool
    {
        return $this->matched >= $this->minimum
            && ($this->maximum === null || $this->matched <= $this->maximum);
    }

    public function withMatch(): self
    {
        return new self(
            anyArgument: $this->anyArgument,
            literalId: $this->literalId,
            isClaim: $this->isClaim,
            answerTitle: $this->answerTitle,
            minimum: $this->minimum,
            maximum: $this->maximum,
            matched: $this->matched + 1,
        );
    }

    public function describe(): string
    {
        return sprintf(
            '%s(find(%s))',
            $this->isClaim ? 'expect' : 'when',
            $this->anyArgument ? 'any' : (string) $this->literalId,
        );
    }
}
