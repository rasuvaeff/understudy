<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;

/**
 * Matches an object by what one of its getters answers — the readable way to
 * pin an entity argument without reconstructing an equal instance.
 *
 * Only a public, non-static method that needs no arguments is called, and only
 * on an object. Anything else is a mismatch rather than an error: matching
 * runs while the code under test is executing, and a matcher must never be the
 * thing that breaks it.
 *
 * @internal
 */
final readonly class QueryEquals implements ArgumentMatcher
{
    /**
     * @param non-empty-string $method
     */
    public function __construct(
        private string $method,
        private mixed $expected,
    ) {}

    /**
     * The `ReflectionMethod` is built per call on purpose, and the audit that
     * asked about it got an answer rather than a patch: caching the decision
     * per class and calling the getter directly was measured at 94 ns against
     * 104 ns, which is 0.6% of the 1.65 µs a dispatch through this matcher
     * costs. Ten nanoseconds do not buy a process-global cache and the
     * invalidation question that comes with it.
     */
    #[\Override]
    public function matches(mixed $argument): bool
    {
        if (!is_object($argument) || !method_exists($argument, $this->method)) {
            return false;
        }

        $reflection = new \ReflectionMethod($argument, $this->method);

        if (!$reflection->isPublic() || $reflection->isStatic() || $reflection->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        try {
            return $reflection->invoke($argument) === $this->expected;
        } catch (\Throwable) {
            // A getter that throws means "does not match", not "the test run
            // is over".
            return false;
        }
    }

    #[\Override]
    public function describe(): string
    {
        return sprintf('which(%s, %s)', $this->method, ArgumentFormatter::format($this->expected));
    }
}
