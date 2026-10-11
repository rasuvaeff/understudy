<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Expectation;

use Rasuvaeff\Understudy\Captor;
use Rasuvaeff\Understudy\Cardinality;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Matcher\AnyArgument;
use Rasuvaeff\Understudy\Matcher\ArgumentMatcher;
use Rasuvaeff\Understudy\Matcher\Capturing;
use Rasuvaeff\Understudy\Matcher\EmptyTail;
use Rasuvaeff\Understudy\Matcher\TailMatcher;
use Rasuvaeff\Understudy\Matcher\Unspelled;

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
     * Precomputed so dispatch pays one boolean read per matched call, not an
     * argument walk.
     */
    private readonly bool $capturing;

    /**
     * Whether this expectation says nothing about any argument: every
     * position is `Arg::any()`, an unspelled optional, or a tail that stands
     * for "the rest does not matter". Precomputed, because dispatch reads it
     * per candidate to order specific expectations before such catch-alls.
     */
    private readonly bool $generic;

    /**
     * A constraint over the whole argument list, from `where()`. Null for
     * every expectation the test did not narrow this way.
     *
     * @var \Closure(mixed...): bool|null
     */
    private ?\Closure $guard = null;

    /**
     * How the guard reads in a failure message.
     *
     * @var non-empty-string|null
     */
    private ?string $guardDescription = null;

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
        $capturing = false;
        $generic = true;
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

            if ($argument instanceof Capturing) {
                $capturing = true;
            }

            // Specificity, decided here once rather than per dispatch: an
            // argument that says something — a literal, any narrowing
            // matcher, or a tail pinned empty by Arg::none() — makes the
            // whole expectation specific. The ones that say nothing:
            // Arg::any(), an unspelled optional, and rest()/remaining(),
            // which stand for "the rest of the arity does not matter".
            if ($argument instanceof AnyArgument || $argument instanceof Unspelled) {
                continue;
            }

            if ($argument instanceof TailMatcher && !$argument instanceof EmptyTail) {
                continue;
            }

            $generic = false;
        }

        $this->tailMatcher = $tailMatcher;
        $this->capturing = $capturing;
        $this->generic = $generic;
    }

    /**
     * Whether no position of this expectation narrows the call — the tier it
     * dispatches in. A generic expectation is tried only after every
     * specific one for the same method refused the call, whichever order
     * the two were declared in.
     */
    public function isGeneric(): bool
    {
        return $this->generic;
    }

    public function hasCaptors(): bool
    {
        return $this->capturing;
    }

    /**
     * Attaches the `where()` guard: a predicate over the whole argument list,
     * asked after every position already matched.
     *
     * Evaluated inside {@see matchesArguments()} on purpose: that is the one
     * place every walk asks an expectation whether a call is its — dispatch,
     * the by-reference slot walk (`hasActionFor()`), a `verify()` probe — so
     * a guard either narrows everywhere or nowhere, and no walk can disagree
     * with the dispatcher about which expectation answers.
     *
     * One guard per expectation; a second `where()` is refused where it is
     * written rather than silently replacing the first.
     *
     * @param callable(mixed...): bool $guard
     * @param non-empty-string        $description how the guard reads in a failure message
     */
    public function setGuard(callable $guard, string $description): void
    {
        if ($this->guard !== null) {
            throw InvalidCallSpecification::guardAlreadyNarrowed($this->method, $this->guardDescription);
        }

        $this->guard = $guard(...);
        $this->guardDescription = $description;
    }

    /**
     * Whether a call the positions already accepted passes the `where()`
     * guard, when there is one.
     *
     * The guard is the test's own code and runs inside the code under test;
     * an exception it raises is the test's own error and propagates — unlike
     * a matcher, which must survive hostile arguments, a predicate that
     * breaks is a bug in the sentence that names it.
     *
     * @param list<mixed> $args
     */
    private function acceptsGuard(array $args): bool
    {
        if ($this->guard === null) {
            return true;
        }

        return ($this->guard)(...$args);
    }

    /**
     * Records each capturing argument's value from a call this expectation
     * matched, and answers the captors that recorded so the caller can tie
     * their lifetime to its context.
     *
     * Called after the whole specification matched, never from `matches()`:
     * a matcher is asked about calls the rest of the specification may yet
     * reject, and about no call at all while a failure message is rendered.
     *
     * @param list<mixed> $args
     *
     * @return list<Captor>
     */
    public function captureFrom(array $args): array
    {
        $captors = [];

        /** @var mixed $argument */
        foreach ($this->args as $position => $argument) {
            if ($argument instanceof Capturing && \array_key_exists($position, $args)) {
                $argument->captor->record($args[$position]);
                $captors[] = $argument->captor;
            }
        }

        return $captors;
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

            if (!$this->tailMatcher->matchesTail(array_slice($args, $fixed))) {
                return false;
            }

            return $this->acceptsGuard($args);
        }

        if ($this->argumentCount === 0) {
            return $args === [] && $this->acceptsGuard($args);
        }

        return count($args) === $this->argumentCount
            && $this->matchesPositions($args, $this->argumentCount)
            && $this->acceptsGuard($args);
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
     * Whether the other expectation names exactly this call: same method, and
     * the same specification in every position — literals by identity, and
     * matchers by class and by what parameterises them.
     *
     * Matchers are compared with `==` deliberately: two `Arg::int(min: 1,
     * max: 5)` built on different lines are the same specification, and
     * identity would miss every collision but a reused instance. The loose
     * comparison can only conflate matchers of one class whose configuration
     * compares equal — which is what "the same specification" means.
     *
     * Overlap is not equality: `find(Arg::any())` and `find(7)` accept the
     * same call and are different specifications, which is exactly the
     * broad-fallback layering the dispatcher documents.
     */
    public function specEquals(self $other): bool
    {
        if ($this->method !== $other->method || $this->argumentCount !== $other->argumentCount) {
            return false;
        }

        /** @var mixed $argument */
        foreach ($this->args as $position => $argument) {
            /** @var mixed $counterpart */
            $counterpart = $other->args[$position];

            if ($argument instanceof ArgumentMatcher) {
                if (
                    !$counterpart instanceof ArgumentMatcher
                    || $argument::class !== $counterpart::class
                    || $argument != $counterpart
                ) {
                    return false;
                }

                continue;
            }

            if ($counterpart instanceof ArgumentMatcher || $argument !== $counterpart) {
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
     * How this expectation reads back next to a call it refused: like
     * {@see describe()}, with every argument that rejected the call marked.
     *
     * A matcher is asked again here, because only it knows whether it accepts
     * a value — and it is asked defensively. This runs during dispatch, inside
     * the code under test: a matcher that throws while the message is being
     * built would replace the refusal with its own exception, and the reader
     * would never learn what was actually refused. A matcher that breaks
     * counts as one that did not accept, and is marked as such.
     *
     * @param list<mixed> $args the call's arguments
     *
     * @return non-empty-string
     */
    public function describeAgainst(array $args): string
    {
        return ArgumentFormatter::scope(function () use ($args): string {
            $parts = [];

            /** @var mixed $argument */
            foreach ($this->args as $position => $argument) {
                $rendered = $argument instanceof ArgumentMatcher
                    ? $argument->describe()
                    : ArgumentFormatter::format($argument);

                $parts[] = $this->accepts($position, $argument, $args)
                    ? $rendered
                    : '*' . $rendered . '*';
            }

            return $this->method
                . '(' . implode(', ', $parts) . ')'
                . $this->describeGuardAgainst($args);
        });
    }

    /**
     * The `where()` segment of a rendered line: plain when the guard accepted
     * the call (or there is none), marked like a rejecting argument when the
     * positions matched and the guard did not — which is the sentence a
     * joint constraint refused, not any one position.
     *
     * @param list<mixed> $args
     */
    private function describeGuardAgainst(array $args): string
    {
        if ($this->guard === null) {
            return '';
        }

        \assert($this->guardDescription !== null);

        return $this->acceptsGuardDefensively($args)
            ? ' ' . $this->guardDescription
            : ' *' . $this->guardDescription . '*';
    }

    /**
     * The guard re-asked while a failure message is rendered — defensively,
     * exactly like a matcher is: this runs during dispatch, inside the code
     * under test, and a guard that throws on the way to a message would
     * replace the refusal with its own exception. A guard that breaks counts
     * as one that did not accept.
     *
     * @param list<mixed> $args
     */
    private function acceptsGuardDefensively(array $args): bool
    {
        \assert($this->guard !== null);

        try {
            return ($this->guard)(...$args);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether one declared argument accepts what arrived in its place.
     *
     * An argument the call never carried is not accepted by anything: that is
     * how an expectation of a wider arity reads back with its extra positions
     * marked.
     *
     * @param int<0, max> $position
     * @param list<mixed> $args
     */
    private function accepts(int $position, mixed $expected, array $args): bool
    {
        try {
            if ($expected instanceof TailMatcher) {
                return $expected->matchesTail(array_slice($args, $position));
            }

            if (!array_key_exists($position, $args)) {
                return false;
            }

            return $expected instanceof ArgumentMatcher
                ? $expected->matches($args[$position])
                : $expected === $args[$position];
        } catch (\Throwable) {
            return false;
        }
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
            )) . ')' . ($this->guardDescription === null ? '' : ' ' . $this->guardDescription),
        );
    }
}
