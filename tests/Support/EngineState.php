<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Support;

/**
 * The model the engine lifecycle is checked against: the specifications
 * registered so far, in registration order, and the log of dispatched
 * `find()` calls with one accounted flag per entry.
 *
 * Immutable by construction — every transition returns a fresh instance — so
 * the state machine runner can replay a shrunk sequence deterministically.
 *
 * The oracle walks the specifications backwards (most recently registered
 * first) without using any matcher implementation of the engine's.
 */
final readonly class EngineState
{
    /**
     * @param list<EngineSpec> $specs      registration order, oldest first
     * @param list<int>        $loggedIds  the argument of every dispatched call, in order
     * @param list<bool>       $accounted  per logged call, whether something accounted for it
     */
    public function __construct(
        public array $specs = [],
        public array $loggedIds = [],
        public array $accounted = [],
    ) {}

    /**
     * The specification that would answer `find($id)`: the last registered
     * one whose predicate accepts the call.
     */
    public function answerFor(int $id): ?EngineSpec
    {
        foreach (array_reverse($this->specs) as $spec) {
            if ($spec->accepts($id)) {
                return $spec;
            }
        }

        return null;
    }

    public function afterDispatch(int $id): self
    {
        $answering = null;

        foreach ($this->specs as $index => $spec) {
            if ($spec->accepts($id)) {
                $answering = $index;
            }
        }

        $specs = $this->specs;

        if ($answering !== null) {
            $specs[$answering] = $specs[$answering]->withMatch();
        }

        return new self(
            specs: $specs,
            loggedIds: [...$this->loggedIds, $id],
            accounted: [...$this->accounted, $answering !== null && $specs[$answering]->isClaim],
        );
    }

    public function withSpec(EngineSpec $spec): self
    {
        return new self(
            specs: [...$this->specs, $spec],
            loggedIds: $this->loggedIds,
            accounted: $this->accounted,
        );
    }

    /**
     * A successful explicit verify claims every logged call the probe
     * matches, idempotently over what was already accounted for.
     */
    public function withVerified(bool $anyArgument, int $literalId): self
    {
        $accounted = [];

        foreach ($this->loggedIds as $index => $logged) {
            $matched = $anyArgument || $logged === $literalId;

            $accounted[] = $this->accounted[$index] || $matched;
        }

        return new self(specs: $this->specs, loggedIds: $this->loggedIds, accounted: $accounted);
    }

    /**
     * A settled checkpoint drops the satisfied claims and every accounted
     * call; stubs and unaccounted calls carry into the next phase.
     */
    public function settled(): self
    {
        $specs = array_values(array_filter(
            $this->specs,
            static fn(EngineSpec $spec): bool => !$spec->isClaim,
        ));

        $loggedIds = [];
        $accounted = [];

        foreach ($this->loggedIds as $index => $logged) {
            if ($this->accounted[$index]) {
                continue;
            }

            $loggedIds[] = $logged;
            $accounted[] = false;
        }

        return new self(specs: $specs, loggedIds: $loggedIds, accounted: $accounted);
    }

    public function callCount(): int
    {
        return count($this->loggedIds);
    }

    public function hasUnaccountedCalls(): bool
    {
        return in_array(false, $this->accounted, true);
    }

    public function claimsViolated(): bool
    {
        foreach ($this->specs as $spec) {
            if ($spec->isClaim && !$spec->withinCardinality()) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many logged calls the given probe matches — the number an explicit
     * verify counts over the whole log.
     */
    public function matchingCallCount(bool $anyArgument, int $literalId): int
    {
        $matches = 0;

        foreach ($this->loggedIds as $logged) {
            if ($anyArgument || $logged === $literalId) {
                ++$matches;
            }
        }

        return $matches;
    }
}
