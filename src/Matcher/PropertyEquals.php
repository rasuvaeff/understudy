<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;

/**
 * Matches an object by one of its public properties — `which()` for a value
 * object that exposes `public readonly` fields instead of getters.
 *
 * Only a public, non-static, initialized property is read, and only on an
 * object. Anything else is a mismatch rather than an error: matching runs while
 * the code under test is executing, and a matcher must never be the thing that
 * breaks it — a `get` hook that throws included.
 *
 * @internal
 */
final readonly class PropertyEquals implements ArgumentMatcher
{
    /**
     * @param non-empty-string $property
     */
    public function __construct(
        private string $property,
        private mixed $expected,
    ) {}

    #[\Override]
    public function matches(mixed $argument): bool
    {
        if (!is_object($argument) || !property_exists($argument, $this->property)) {
            return false;
        }

        $reflection = new \ReflectionProperty($argument, $this->property);

        if (!$reflection->isPublic() || $reflection->isStatic()) {
            return false;
        }

        try {
            return $reflection->isInitialized($argument) && $reflection->getValue($argument) === $this->expected;
        } catch (\Throwable) {
            return false;
        }
    }

    #[\Override]
    public function describe(): string
    {
        return sprintf('whichProperty(%s, %s)', $this->property, ArgumentFormatter::format($this->expected));
    }
}
