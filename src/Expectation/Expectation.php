<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Expectation;

use Rasuvaeff\Understudy\Cardinality;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
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
    /**
     * Actions are a chain, not a single value: one is consumed per match, and
     * the last one keeps answering after the chain runs out.
     *
     * @var list<Action>
     */
    private array $actions = [];

    /** @var int<0, max> */
    private int $matchCount = 0;

    private ?Cardinality $cardinality = null;

    private bool $ordered = false;

    private bool $claim = false;

    /** @var list<positive-int> */
    private array $matchedSequences = [];

    /**
     * @param non-empty-string $method
     * @param list<mixed>      $args literal values and/or ArgumentMatcher instances
     */
    public function __construct(
        public readonly string $method,
        public readonly array $args,
    ) {
        $last = count($args) - 1;

        /** @var mixed $argument */
        foreach ($args as $position => $argument) {
            if ($argument instanceof TailMatcher && $position !== $last) {
                // Caught here rather than while matching: a misplaced
                // remaining() otherwise behaves as a silent wildcard for that
                // one argument, which is worse than any error message.
                throw InvalidCallSpecification::misplacedTailMatcher(
                    $method,
                    $position,
                    $argument->describe(),
                );
            }
        }
    }

    /**
     * Replaces the action in the given slot, so that a builder can refine the
     * behaviour it just declared without opening a new link in the chain.
     *
     * @param int<0, max> $slot
     */
    public function setAction(Action $action, int $slot = 0): void
    {
        // A builder only ever writes to the current slot or opens the next
        // one, so the chain grows by one at a time and never develops holes.
        if ($slot >= count($this->actions)) {
            $this->actions[] = $action;

            return;
        }

        $actions = $this->actions;
        $actions[$slot] = $action;
        $this->actions = array_values($actions);
    }

    public function hasAction(): bool
    {
        return $this->actions !== [];
    }

    /**
     * Null means "not checked": a plain when() stub allows any number of
     * calls, and only becomes a claim about counts once times() says so.
     */
    public function cardinality(): ?Cardinality
    {
        return $this->cardinality;
    }

    public function setCardinality(Cardinality $cardinality): void
    {
        $this->cardinality = $cardinality;
    }

    /**
     * An expectation from expect() is a claim about what happens, so a call it
     * matches is accounted for. A when() stub is only permission, and leaves
     * the call unaccounted — which is what nothingElse() goes on to notice.
     */
    public function declareClaim(): void
    {
        $this->claim = true;
    }

    public function isClaim(): bool
    {
        return $this->claim;
    }

    /**
     * @return list<positive-int>
     */
    public function matchedSequences(): array
    {
        return $this->matchedSequences;
    }

    /**
     * Ordered expectations must be satisfied in the order they were declared,
     * relative to each other; unrelated calls may happen in between.
     */
    public function requireOrder(): void
    {
        $this->ordered = true;
    }

    public function isOrdered(): bool
    {
        return $this->ordered;
    }

    /**
     * @return int<0, max>
     */
    public function matchCount(): int
    {
        return $this->matchCount;
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

    /**
     * Counts this match. Separate from performing an action, because an
     * expectation without one still constrains how often the call may happen.
     */
    public function recordMatch(Invocation $invocation): void
    {
        $this->matchCount++;
        $this->matchedSequences[] = $invocation->sequence;

        if ($this->claim) {
            $invocation->markAccounted();
        }
    }

    /**
     * The action for this match: one link of the chain per match, with the
     * last link answering every call after the chain runs out.
     */
    public function performAction(Invocation $invocation): mixed
    {
        \assert($this->actions !== []);

        $link = max(0, min($this->matchCount - 1, count($this->actions) - 1));

        return $this->actions[$link]->perform($invocation);
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
