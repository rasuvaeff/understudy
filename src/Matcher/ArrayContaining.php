<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;

/**
 * Matches an array that contains the given entries, ignoring anything else it
 * carries — the point being to pin the part of a payload a test cares about
 * without restating the rest.
 *
 * A list is matched by value, a map by key and value.
 *
 * @internal
 */
final readonly class ArrayContaining implements ArgumentMatcher
{
    /**
     * @param array<array-key, mixed> $expected
     */
    public function __construct(private array $expected) {}

    #[\Override]
    public function matches(mixed $argument): bool
    {
        if (!is_array($argument)) {
            return false;
        }

        if (array_is_list($this->expected)) {
            /** @var mixed $value */
            foreach ($this->expected as $value) {
                if (!in_array($value, $argument, strict: true)) {
                    return false;
                }
            }

            return true;
        }

        /** @var mixed $value */
        foreach ($this->expected as $key => $value) {
            if (!array_key_exists($key, $argument) || $argument[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    #[\Override]
    public function describe(): string
    {
        return 'containing(' . ArgumentFormatter::format($this->expected) . ')';
    }
}
