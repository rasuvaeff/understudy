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

    /** @var int<0, max> */
    private int $declarationOrder = 0;

    /** @var list<positive-int> */
    private array $matchedSequences = [];

    private readonly int $argumentCount;

    private readonly ?TailMatcher $tailMatcher;

    /**
     * @param non-empty-string $method
     * @param list<mixed>      $args literal values and/or ArgumentMatcher instances
     */
    public function __construct(
        public readonly string $method,
        public readonly array $args,
    ) {
        $this->argumentCount = count($args);
        $tailMatcher = null;
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

            if ($position === $last && $argument instanceof TailMatcher) {
                $tailMatcher = $argument;
            }
        }

        $this->tailMatcher = $tailMatcher;
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
     * Where this expectation stands among every expectation of its context —
     * what an ordering claim is measured against.
     *
     * @param int<0, max> $order
     */
    public function setDeclarationOrder(int $order): void
    {
        $this->declarationOrder = $order;
    }

    /**
     * @return int<0, max>
     */
    public function declarationOrder(): int
    {
        return $this->declarationOrder;
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

        return $this->matchesArguments($args);
    }

    /**
     * Matches arguments after the caller has already selected this method's
     * expectation bucket.
     *
     * @param list<mixed> $args
     */
    public function matchesArguments(array $args): bool
    {
        if ($this->tailMatcher !== null) {
            $fixed = $this->argumentCount - 1;
            /** @var int<0, max> $fixed */

            if (count($args) < $fixed || !$this->matchesPositions($args, $fixed)) {
                return false;
            }

            return $this->tailMatcher->matchesTail(array_slice($args, $fixed));
        }

        if ($this->argumentCount === 0) {
            return $args === [];
        }

        return count($args) === $this->argumentCount
            && $this->matchesPositions($args, $this->argumentCount);
    }

    /**
     * @param list<mixed> $args
     * @param int<0, max> $length
     */
    private function matchesPositions(array $args, int $length): bool
    {
        for ($position = 0; $position < $length; ++$position) {
            /** @var mixed $actual */
            $actual = $args[$position];
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
        return ArgumentFormatter::scope(
            fn(): string => $this->method . '(' . implode(', ', array_map(
                static fn(mixed $arg): string => $arg instanceof ArgumentMatcher
                    ? $arg->describe()
                    : ArgumentFormatter::format($arg),
                $this->args,
            )) . ')',
        );
    }
}
