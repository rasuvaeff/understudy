<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * @internal
 */
final readonly class Satisfying implements ArgumentMatcher
{
    /**
     * @param callable(mixed): bool $predicate
     * @param non-empty-string      $description shown in place of the argument
     */
    public function __construct(
        private mixed $predicate,
        private string $description = 'satisfies(…)',
    ) {}

    #[\Override]
    public function matches(mixed $argument): bool
    {
        return ($this->predicate)($argument);
    }

    #[\Override]
    public function describe(): string
    {
        return $this->description;
    }
}
