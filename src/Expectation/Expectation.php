<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Expectation;

use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Matcher\ArgumentMatcher;
use Rasuvaeff\Understudy\Matcher\TailMatcher;

/**
 * One configured call: which method with which arguments, and what happens
 * when it arrives.
 *
 * @internal
 */
final class Expectation
{
    private ?Action $action = null;

    /** @var int<0, max> */
    private int $matchCount = 0;

    /**
     * @param non-empty-string $method
     * @param list<mixed>      $args literal values and/or ArgumentMatcher instances
     */
    public function __construct(
        public readonly string $method,
        public readonly array $args,
    ) {}

    public function setAction(Action $action): void
    {
        $this->action = $action;
    }

    public function hasAction(): bool
    {
        return $this->action !== null;
    }

    /**
     * @param non-empty-string $method
     * @param list<mixed>      $args
     */
    public function matches(string $method, array $args): bool
    {
        if ($method !== $this->method) {
            return false;
        }

        // Indexed rather than end(), which moves the array pointer and so
        // counts as modifying a readonly property.
        /** @var mixed $tail */
        $tail = $this->args === [] ? null : $this->args[count($this->args) - 1];

        // A tail matcher stands for every remaining argument, so it decides
        // the arity instead of obeying it.
        if ($tail instanceof TailMatcher) {
            $fixed = count($this->args) - 1;

            return count($args) >= $fixed
                && $this->matchesPositions(array_slice($args, 0, $fixed))
                && $tail->matchesTail(array_slice($args, $fixed));
        }

        return count($args) === count($this->args) && $this->matchesPositions($args);
    }

    /**
     * @param list<mixed> $args
     */
    private function matchesPositions(array $args): bool
    {
        /** @var mixed $actual */
        foreach ($args as $position => $actual) {
            /** @var mixed $expected */
            $expected = $this->args[$position];

            $matched = $expected instanceof ArgumentMatcher
                ? $expected->matches($actual)
                : $expected === $actual;

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    public function answer(Invocation $invocation): mixed
    {
        $this->matchCount++;

        return $this->action?->perform($invocation);
    }

    /**
     * @return int<0, max>
     */
    public function matchCount(): int
    {
        return $this->matchCount;
    }

    /**
     * How this expectation reads back in a failure message.
     *
     * @return non-empty-string
     */
    public function describe(): string
    {
        return $this->method . '(' . implode(', ', array_map(
            static fn(mixed $arg): string => $arg instanceof ArgumentMatcher
                ? $arg->describe()
                : ArgumentFormatter::format($arg),
            $this->args,
        )) . ')';
    }
}
