<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Expectation;

use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Matcher\ArgumentMatcher;

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
        if ($method !== $this->method || count($args) !== count($this->args)) {
            return false;
        }

        /** @var mixed $expected */
        foreach ($this->args as $position => $expected) {
            /** @var mixed $actual */
            $actual = $args[$position];

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
