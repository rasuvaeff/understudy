<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

use Rasuvaeff\Understudy\Codegen\Blueprint;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Matcher\ArgumentMatcher;

/**
 * Everything one understudy carries. It lives here rather than on the double
 * itself: a double must expose no member beyond the contract it stands in for.
 *
 * @internal
 */
final class DoubleState
{
    /** @var list<Expectation> */
    private array $expectations = [];

    /** @var list<Expectation> */
    private array $matchingExpectations = [];

    /** @var array<non-empty-string, list<Expectation>> */
    private array $matchingByMethod = [];

    private const string NO_ARGUMENTS = '__no_arguments__';

    /** @var array<non-empty-string, array<string, list<Expectation>>> */
    private array $matchingByMethodAndFirstLiteral = [];

    /** @var array<non-empty-string, list<Expectation>> */
    private array $matchingByMethodWithoutFirstLiteral = [];

    /** @var list<Invocation> */
    private array $callLog = [];

    /** @var non-empty-string|null */
    private ?string $label = null;

    private ?object $forwardingTarget = null;

    /** @var array<non-empty-string, ReferenceSlot> */
    private array $referenceSlots = [];

    /** @var array<non-empty-string, bool> */
    private array $seededSlots = [];

    /**
     * @param bool $nested true when a loose default created this double rather
     *                     than the test asking for it, which is what stops the
     *                     chain at one level
     */
    public function __construct(
        public readonly Blueprint $blueprint,
        private Mode $mode = Mode::Loose,
        public readonly bool $nested = false,
    ) {}

    public function mode(): Mode
    {
        return $this->mode;
    }

    public function setMode(Mode $mode): void
    {
        $this->mode = $mode;
    }

    /**
     * @return non-empty-string
     */
    public function label(): string
    {
        return $this->label ?? $this->blueprint->displayName();
    }

    /**
     * @param non-empty-string $label
     */
    /**
     * The real instance a forwarding call delegates to, or null when the double
     * stands alone. Remembered separately from the mode: `for($real)` records
     * the instance so `callOriginal()` works, without making every unmatched
     * call go through it.
     */
    public function forwardingTarget(): ?object
    {
        return $this->forwardingTarget;
    }

    public function setForwardingTarget(object $target): void
    {
        $this->forwardingTarget = $target;
    }

    /**
     * @param non-empty-string $label
     */
    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    /**
     * The stable place a by-reference return points into, seeded on first use.
     *
     * Kept per method so that two by-reference methods on one double do not
     * alias each other, and handed back as an object so that the reference is
     * taken in the generated method rather than here.
     *
     * @param non-empty-string $method
     */
    public function referenceSlot(string $method, mixed $seed, bool $replace): ReferenceSlot
    {
        $slot = $this->referenceSlots[$method] ??= new ReferenceSlot();

        if ($replace || !($this->seededSlots[$method] ?? false)) {
            $slot->value = $seed;
            $this->seededSlots[$method] = true;
        }

        return $slot;
    }

    /**
     * Whether the expectation that would answer this call has an action, as
     * opposed to the call falling through to the mode's own answer.
     *
     * Asked only on the by-reference path, where the difference decides whether
     * the stable slot is replaced or left alone.
     *
     * @param non-empty-string $method
     * @param list<mixed>      $args
     */
    public function hasActionFor(string $method, array $args): bool
    {
        // The same walk the dispatcher makes, in the same order, stopping at
        // the same place. Scanning declaration order and answering "some
        // matching expectation has an action" would disagree with it exactly
        // when a newer `expect(...)->times(1)` shadows an older stub — and the
        // slot would then be replaced by a value dispatch never returned.
        foreach ($this->expectationsFor($method) as $expectation) {
            if ($expectation->matchesArguments($args)) {
                return $expectation->hasAction();
            }
        }

        return false;
    }

    public function addExpectation(Expectation $expectation): void
    {
        $this->expectations[] = $expectation;
        $this->matchingExpectations[] = $expectation;

        $this->addToMatchingIndexes($expectation);
    }

    /**
     * Most recently registered first: a later `when()` for the same call
     * overrides an earlier one, and earlier ones stay reachable as fallbacks.
     *
     * @return list<Expectation>
     */
    public function expectations(): array
    {
        return array_reverse($this->matchingExpectations);
    }

    /**
     * Most recently registered expectations for one method, in matching order.
     *
     * The overwhelmingly common shape — a method with a single stub — is
     * answered by a lookup and nothing else. Indexing only earns its keep once
     * there is something to choose between, and computing a key for a method
     * that has one expectation would tax every call in every suite to speed up
     * the rare one.
     *
     * @param non-empty-string $method
     * @param list<mixed>|null $args
     * @return list<Expectation>
     */
    public function expectationsFor(string $method, ?array $args = null): array
    {
        /** @var list<Expectation> $all */
        $all = $this->matchingByMethod[$method] ?? [];

        if (count($all) <= 1) {
            return $all;
        }

        if ($args === null) {
            return array_reverse($all);
        }

        $key = $args === [] ? self::NO_ARGUMENTS : $this->literalKey($args[0]);
        /** @var list<Expectation> $indexed */
        $indexed = $key === null
            ? []
            : ($this->matchingByMethodAndFirstLiteral[$method][$key] ?? []);

        /** @var list<Expectation> $fallback */
        $fallback = $this->matchingByMethodWithoutFirstLiteral[$method] ?? [];

        if ($fallback === []) {
            return array_reverse($indexed);
        }

        if ($indexed === []) {
            return array_reverse($fallback);
        }

        // Both kinds can answer this call, so the declared order decides
        // between them — the same rule the unindexed walk followed.
        $candidates = [...$indexed, ...$fallback];

        usort(
            $candidates,
            static fn(Expectation $left, Expectation $right): int
                => $right->declarationOrder() <=> $left->declarationOrder(),
        );

        return $candidates;
    }

    /**
     * In the order they were written, which is what an ordering claim is about
     * — the reverse of what matching wants.
     *
     * @return list<Expectation>
     */
    public function declaredExpectations(): array
    {
        return $this->expectations;
    }

    /**
     * Drops what the current phase has settled: expectations that are done and
     * the calls already accounted for. Modes, labels and the understudy itself
     * survive, so the next phase carries on with the same cast.
     */
    public function settle(): void
    {
        $remaining = static fn(Expectation $expectation): bool => $expectation->cardinality() === null;

        $this->expectations = array_values(array_filter($this->expectations, $remaining));
        $this->matchingExpectations = array_values(array_filter($this->matchingExpectations, $remaining));

        $this->matchingByMethod = [];
        $this->matchingByMethodAndFirstLiteral = [];
        $this->matchingByMethodWithoutFirstLiteral = [];

        foreach ($this->matchingExpectations as $expectation) {
            $this->addToMatchingIndexes($expectation);
        }

        $this->callLog = array_values(array_filter(
            $this->callLog,
            static fn(Invocation $invocation): bool => !$invocation->isAccounted(),
        ));
    }

    public function record(Invocation $invocation): void
    {
        $this->callLog[] = $invocation;
    }

    /**
     * @return list<Invocation>
     */
    public function callLog(): array
    {
        return $this->callLog;
    }

    private function addToMatchingIndexes(Expectation $expectation): void
    {
        $method = $expectation->method;
        $this->matchingByMethod[$method] ??= [];
        $this->matchingByMethod[$method][] = $expectation;

        $key = $this->firstLiteralKey($expectation);

        if ($key === null) {
            $this->matchingByMethodWithoutFirstLiteral[$method] ??= [];
            $this->matchingByMethodWithoutFirstLiteral[$method][] = $expectation;

            return;
        }

        $this->matchingByMethodAndFirstLiteral[$method][$key] ??= [];
        $this->matchingByMethodAndFirstLiteral[$method][$key][] = $expectation;
    }

    private function firstLiteralKey(Expectation $expectation): ?string
    {
        return $expectation->args === []
            ? self::NO_ARGUMENTS
            : $this->literalKey($expectation->args[0]);
    }

    /**
     * A cheap, exact discriminator for a first argument that is compared by
     * identity anyway. Anything that is not — a matcher, or a value whose
     * identity is not its content — has no key and stays in the walked list.
     */
    private function literalKey(mixed $value): ?string
    {
        if ($value instanceof ArgumentMatcher || is_array($value) || is_object($value) || is_resource($value)) {
            return null;
        }

        return serialize($value);
    }
}
