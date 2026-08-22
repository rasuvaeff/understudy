<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * @internal
 */
final readonly class StringMatching implements ArgumentMatcher
{
    /**
     * @param non-empty-string|null $pattern a PCRE pattern, delimiters included
     */
    public function __construct(private ?string $pattern = null) {}

    #[\Override]
    public function matches(mixed $argument): bool
    {
        if (!is_string($argument)) {
            return false;
        }

        return $this->pattern === null || preg_match($this->pattern, $argument) === 1;
    }

    #[\Override]
    public function describe(): string
    {
        return $this->pattern === null ? 'string()' : 'string(matches: ' . $this->pattern . ')';
    }
}
