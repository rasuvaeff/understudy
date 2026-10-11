<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

use Rasuvaeff\Understudy\Codegen\Blueprint;
use Rasuvaeff\Understudy\Exception\ConflictingExpectation;
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

    /** @var array<non-empty-string, array<string, list<Expectation>>>|null */
    private ?array $matchingByMethodAndFirstLiteral = null;

    /** @var array<non-empty-string, list<Expectation>>|null */
    private ?array $matchingByMethodWithoutFirstLiteral = null;

    /** @var list<Invocation> */
    private array $callLog = [];

    /** @var non-empty-string|null */
    private ?string $label = null;

    /** @var non-empty-string|null */
    private ?string $defaultLabel = null;

    private ?object $forwardingTarget = null;

    /** @var array<non-empty-string, ReferenceSlot> */
    private array $referenceSlots = [];

    /**
     * What the code under test wrote into rendered hooked properties. Kept
     * apart from a written-flags map on purpose: `null` is a value someone
     * wrote, and `array_key_exists` is what tells it from "never written".
     *
     * @var array<non-empty-string, mixed>
     */
    private array $propertyValues = [];

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

    private bool $lean = false;

    public function mode(): Mode
    {
        return $this->mode;
    }

    /**
     * A lean understudy records every invocation — method, arguments,
     * sequence — but not the value it answered. One-way, like the modes: the
     * point is a guarantee about what the log holds, and a guarantee that can
     * be switched off mid-test is a note, not a guarantee.
     */
    public function makeLean(): void
    {
        $this->lean = true;
    }

    public function isLean(): bool
    {
        return $this->lean;
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
        return $this->label ?? $this->defaultLabel ?? $this->blueprint->displayName();
    }

    /**
     * The label a report uses when the test set none: the contract's short
     * name, numbered by the owning context once it holds more than one double
     * of that contract. An explicit `setLabel()` still outranks it.
     *
     * @param non-empty-string $label
     */
    public function setDefaultLabel(string $label): void
    {
        $this->defaultLabel = $label;
    }

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
     * @param non-empty-string $property
     */
    public function writeProperty(string $property, mixed $value): void
    {
        $this->propertyValues[$property] = $value;
    }

    /**
     * @param non-empty-string $property
     */
    public function hasPropertyValue(string $property): bool
    {
        return \array_key_exists($property, $this->propertyValues);
    }

    /**
     * @param non-empty-string $property
     */
    public function propertyValue(string $property): mixed
    {
        return $this->propertyValues[$property] ?? null;
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
        // the same place — tier order included, which is why the args are
        // passed rather than walking every expectation of the method: the
        // tier a call's candidates come in is part of what "the same order"
        // means. Scanning declaration order and answering "some matching
        // expectation has an action" would disagree with it exactly when a
        // newer generic stub shadows an older specific one for the same
        // call — and the slot would then be replaced by a value dispatch
        // never returned. (An identical specification cannot layer like
        // that any more — assertComposes() refuses it — but an overlapping
        // one still does.)
        foreach ($this->expectationsFor($method, $args) as $expectation) {
            if ($expectation->matchesArguments($args)) {
                return $expectation->hasAction();
            }
        }

        return false;
    }

    public function addExpectation(Expectation $expectation): void
    {
        $this->assertComposes($expectation);

        $this->expectations[] = $expectation;
        $this->matchingExpectations[] = $expectation;

        $method = $expectation->method;
        $this->matchingByMethod[$method] ??= [];
        $this->matchingByMethod[$method][] = $expectation;

        $count = count($this->matchingByMethod[$method]);

        if ($count === 2) {
            $this->buildMatchingIndexes($method);
        }

        if ($count > 2) {
            $this->addToMatchingIndex($expectation);
        }
    }

    /**
     * Refuses a registration that names a call another registration already
     * specifies when the two have no working layering. Whichever is declared
     * later takes the dispatch, so a stub followed by an action-less
     * expectation answers the mode default instead of the stubbed value, and
     * anything registered after a counted expectation starves its count —
     * both silently, which is the one thing this library promises not to do.
     *
     * Two plain stubs stay allowed: "most recently registered wins, earlier
     * ones remain as fallbacks" is documented layering, and overlapping but
     * different specifications (a broad fallback and a narrow claim) are the
     * reason that rule exists.
     *
     * A `times()` added to a stub later makes the *stub* counted, and later
     * registrations then collide with it the same way.
     */
    private function assertComposes(Expectation $incoming): void
    {
        /** @var list<Expectation> $registered */
        $registered = $this->matchingByMethod[$incoming->method] ?? [];

        // At registration time an expect() is exactly a claim — it carries
        // its cardinality already — and a when() carries neither; a times()
        // put on a stub later makes the *registered* side counted, which the
        // cardinality check below sees on the next registration.
        $incomingCounts = $incoming->isClaim();

        foreach ($registered as $existing) {
            if (!$existing->specEquals($incoming)) {
                continue;
            }

            if ($existing->cardinality() !== null) {
                throw $incomingCounts
                    ? ConflictingExpectation::duplicateCountedExpectation($this->label(), $existing->describe())
                    : ConflictingExpectation::stubAfterCountedExpectation($this->label(), $existing->describe());
            }

            if ($incomingCounts) {
                throw ConflictingExpectation::claimAfterStub($this->label(), $existing->describe());
            }
        }
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
     * Two tiers, whichever order they were declared in: an expectation that
     * pins at least one argument to something narrower than "anything" is
     * tried before one that says nothing about any argument, so a catch-all
     * registered after a specific stub cannot steal the calls the specific
     * one describes — the specific one is never unreachable, and the trap
     * needs no diagnosis because it cannot be written. Within a tier the
     * documented layering stands: most recently registered first, earlier
     * ones as fallbacks. Two specifics are never compared against each other
     * for narrowness; registration order decides between them.
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
        $indexed = $this->indexedExpectations($method, $key);

        /** @var list<Expectation> $fallback */
        $fallback = $this->matchingByMethodWithoutFirstLiteral === null
            ? []
            : ($this->matchingByMethodWithoutFirstLiteral[$method] ?? []);

        if ($fallback === []) {
            $candidates = array_reverse($indexed);
        } elseif ($indexed === []) {
            $candidates = array_reverse($fallback);
        } else {
            $candidates = [...$indexed, ...$fallback];
        }

        if (count($candidates) < 2) {
            return $candidates;
        }

        // The same tier order both lists answer in — a tier crossing inside
        // the index (a literal against a catch-all) and one inside the walked
        // list (a range matcher against a catch-all) are the same rule, so
        // they get the same sort.
        usort(
            $candidates,
            static function (Expectation $left, Expectation $right): int {
                $tier = $left->isGeneric() <=> $right->isGeneric();

                return $tier !== 0
                    ? $tier
                    : $right->declarationOrder() <=> $left->declarationOrder();
            },
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
        $this->matchingByMethodAndFirstLiteral = null;
        $this->matchingByMethodWithoutFirstLiteral = null;

        foreach ($this->matchingExpectations as $expectation) {
            $method = $expectation->method;
            $this->matchingByMethod[$method] ??= [];
            $this->matchingByMethod[$method][] = $expectation;
        }

        foreach ($this->matchingByMethod as $method => $expectations) {
            if (count($expectations) >= 2) {
                $this->buildMatchingIndexes($method);
            }
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

    /**
     * @param non-empty-string $method
     */
    private function buildMatchingIndexes(string $method): void
    {
        /** @var list<Expectation> $expectations */
        $expectations = $this->matchingByMethod[$method] ?? [];

        foreach ($expectations as $expectation) {
            $this->addToMatchingIndex($expectation);
        }
    }

    private function addToMatchingIndex(Expectation $expectation): void
    {
        $method = $expectation->method;

        $key = $this->firstLiteralKey($expectation);

        if ($key === null) {
            $this->matchingByMethodWithoutFirstLiteral ??= [];
            $this->matchingByMethodWithoutFirstLiteral[$method] ??= [];
            $this->matchingByMethodWithoutFirstLiteral[$method][] = $expectation;

            return;
        }

        $this->matchingByMethodAndFirstLiteral ??= [];
        $this->matchingByMethodAndFirstLiteral[$method] ??= [];
        $this->matchingByMethodAndFirstLiteral[$method][$key] ??= [];
        $this->matchingByMethodAndFirstLiteral[$method][$key][] = $expectation;
    }

    /**
     * @param non-empty-string $method
     * @return list<Expectation>
     */
    private function indexedExpectations(string $method, ?string $key): array
    {
        $indexed = [];

        if ($key !== null) {
            $indexes = $this->matchingByMethodAndFirstLiteral;

            if ($indexes !== null) {
                $indexed = $indexes[$method][$key] ?? [];
            }
        }

        return $indexed;
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
     *
     * The key has to agree with `===`, which `serialize()` does not do on its
     * own: `-0.0 === 0.0` is true and their serialisations differ, so a stub
     * armed with one was invisible to a call made with the other — but only
     * once a second expectation on that method brought the index into play,
     * which made an unrelated stub change the first one's behaviour.
     *
     * The other direction needs no handling. `NAN` gets one key for values
     * `===` calls distinct, and that is harmless: the index only narrows the
     * candidates, and `Expectation::matchesArguments()` still compares each
     * one with `===`, rejecting a `NAN` expectation exactly as the walk it
     * replaced did.
     */
    private function literalKey(mixed $value): ?string
    {
        if ($value instanceof ArgumentMatcher || is_array($value) || is_object($value) || is_resource($value)) {
            return null;
        }

        // True for both float zeros and, being strict, for neither the
        // integer `0` nor `'0'` — which are different keys on purpose.
        if ($value === 0.0) {
            return serialize(0.0);
        }

        return serialize($value);
    }
}
