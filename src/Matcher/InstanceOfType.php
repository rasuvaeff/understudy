<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * @internal
 */
final readonly class InstanceOfType implements ArgumentMatcher
{
    /**
     * @param class-string $type
     */
    public function __construct(private string $type) {}

    #[\Override]
    public function matches(mixed $argument): bool
    {
        return $argument instanceof $this->type;
    }

    #[\Override]
    public function describe(): string
    {
        return 'instanceOf(' . $this->type . ')';
    }
}
