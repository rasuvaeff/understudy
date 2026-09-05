<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Exception\OriginalCallUnavailable;
use Rasuvaeff\Understudy\Exception\OutcomeUnavailable;
use Rasuvaeff\Understudy\Runtime\Runtime;

/**
 * One recorded call on an understudy.
 *
 * Instances are handed to `answers()` callbacks while the call is still in
 * flight, and read back afterwards through `Understudy::calls()`. The outcome
 * is therefore filled in once, by the dispatcher, after the answer is known.
 *
 * @api
 */
final class Invocation
{
    private ?Outcome $outcome = null;

    private ?bool $returnedState = null;

    private mixed $returnedValue = null;

    private ?\Throwable $thrownError = null;

    private bool $accounted = false;

    private bool $returnDiscarded = false;

    /** @var list<mixed>|null */
    private ?array $argsAfter = null;

    /**
     * @param non-empty-string $method
     * @param list<mixed>      $args
     * @param positive-int     $sequence position in this context's global call order
     * @param list<mixed>      $liveArgs the arguments as the caller still holds them,
     *                                   references included — what delegation needs,
     *                                   where {@see $args} is a reading of them
     * @param list<int>        $sensitiveArguments positions the contract marked
     *                                   `#[\SensitiveParameter]`; carried on the call so a
     *                                   failure message and a transcript can redact the value
     *                                   the way PHP redacts it in its own traces
     */
    public function __construct(
        public readonly string $method,
        public readonly array $args,
        public readonly int $sequence,
        private readonly ?object $double = null,
        private readonly array $liveArgs = [],
        public readonly array $sensitiveArguments = [],
    ) {}

    /**
     * What the arguments were once the call had been answered.
     *
     * Only a method with a by-reference parameter has one: for every other
     * method the answer cannot change what was passed, and taking the snapshot
     * anyway would cost every call in the suite. Null means "same as
     * {@see $args}".
     *
     * @return list<mixed>|null
     */
    public function argsAfter(): ?array
    {
        return $this->argsAfter;
    }

    /**
     * @param list<mixed> $args
     *
     * @internal
     */
    public function recordFinalArguments(array $args): void
    {
        $this->argsAfter = $args;
    }

    /**
     * @internal
     */
    public function belongsTo(object $double): bool
    {
        return $this->double === $double;
    }

    /**
     * Delegates this call to the double's real instance and returns what it
     * answered — the `answers()` escape hatch for "behave normally, except
     * here".
     *
     * Without a forwarding target it raises `OriginalCallUnavailable` rather
     * than reaching for the parent implementation: the double's constructor
     * never ran, so the parent body would work over state that does not exist.
     *
     * @throws OriginalCallUnavailable
     */
    public function callOriginal(): mixed
    {
        if ($this->double === null) {
            // Only invocations built by the dispatcher carry a double, and
            // those are the only ones an answer ever sees.
            throw OriginalCallUnavailable::withoutTarget('understudy', $this->method);
        }

        // The live arguments, not the log's reading of them: a by-reference
        // parameter is the caller's variable, and the real method is expected
        // to be able to write to it.
        return Runtime::callOriginal($this->double, $this->method, $this->liveArgs);
    }

    public function recordOutcome(Outcome $outcome): void
    {
        $this->outcome ??= $outcome;
    }

    /**
     * Records a returned value without allocating an Outcome wrapper. The
     * wrapper remains supported by recordOutcome() for internal compatibility;
     * dispatch uses this scalar path because every call reaches it.
     *
     * @internal
     */
    public function recordReturned(mixed $value): void
    {
        if ($this->returnedState !== null || $this->outcome !== null) {
            return;
        }

        $this->returnedState = true;
        $this->returnedValue = $value;
    }

    /**
     * Records that the call returned without keeping what it returned — the
     * lean double's reading of an outcome. The distinction from "threw" and
     * from "returned null" is kept: `didReturn()` stays true, and `returned()`
     * refuses by name instead of inventing a value.
     *
     * @internal
     */
    public function recordDiscardedReturn(): void
    {
        if ($this->returnedState !== null || $this->outcome !== null) {
            return;
        }

        $this->returnedState = true;
        $this->returnDiscarded = true;
    }

    /**
     * @internal
     */
    public function isReturnDiscarded(): bool
    {
        return $this->returnDiscarded;
    }

    /**
     * @internal
     */
    public function recordThrown(\Throwable $thrown): void
    {
        if ($this->returnedState !== null || $this->outcome !== null) {
            return;
        }

        $this->returnedState = false;
        $this->thrownError = $thrown;
    }

    /**
     * Marks this call as accounted for: an expectation matched it, or a
     * verification claimed it. `nothingElse()` is the question this answers.
     *
     * @internal
     */
    public function markAccounted(): void
    {
        $this->accounted = true;
    }

    /**
     * @internal
     */
    public function isAccounted(): bool
    {
        return $this->accounted;
    }

    public function didReturn(): bool
    {
        return $this->returnedState ?? $this->outcome?->didReturn() ?? false;
    }

    public function didThrow(): bool
    {
        return $this->returnedState !== null
            ? !$this->returnedState
            : $this->outcome?->didThrow() ?? false;
    }

    public function returned(): mixed
    {
        if ($this->returnedState === true) {
            if ($this->returnDiscarded) {
                throw OutcomeUnavailable::discardedByLean($this->method);
            }

            return $this->returnedValue;
        }

        if ($this->returnedState === false) {
            \assert($this->thrownError instanceof \Throwable);

            throw OutcomeUnavailable::threwInstead($this->method, $this->thrownError);
        }

        return $this->outcome?->returned($this->method);
    }

    public function thrown(): ?\Throwable
    {
        return $this->returnedState !== null
            ? $this->thrownError
            : $this->outcome?->thrown();
    }
}
