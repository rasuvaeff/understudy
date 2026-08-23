<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Exception\OriginalCallUnavailable;
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

    private bool $accounted = false;

    /**
     * @param non-empty-string $method
     * @param list<mixed>      $args
     * @param positive-int     $sequence position in this context's global call order
     */
    public function __construct(
        public readonly string $method,
        public readonly array $args,
        public readonly int $sequence,
        public readonly ?string $file = null,
        public readonly ?int $line = null,
        private readonly ?object $double = null,
    ) {}

    /**
     * @internal
     */
    public function belongsTo(object $double): bool
    {
        return $this->double === $double;
    }

    /**
     * @internal called once by the dispatcher when the call finishes
     */
    /**
     * Delegates this call to the double's real instance and returns what it
     * answered — the `answers()` escape hatch for "behave normally, except
     * here".
     *
     * Without a forwarding target it raises `OriginalCallUnavailable` rather
     * than reaching for the parent implementation: the double's constructor
     * never ran, so the parent body would work over state that does not exist.
     *
     * @throws \Rasuvaeff\Understudy\Exception\OriginalCallUnavailable
     */
    public function callOriginal(): mixed
    {
        if ($this->double === null) {
            // Only invocations built by the dispatcher carry a double, and
            // those are the only ones an answer ever sees.
            throw OriginalCallUnavailable::withoutTarget('understudy', $this->method);
        }

        return Runtime::callOriginal($this->double, $this->method, $this->args);
    }

    public function recordOutcome(Outcome $outcome): void
    {
        $this->outcome ??= $outcome;
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
        return $this->outcome?->didReturn() ?? false;
    }

    public function didThrow(): bool
    {
        return $this->outcome?->didThrow() ?? false;
    }

    public function returned(): mixed
    {
        return $this->outcome?->returned($this->method);
    }

    public function thrown(): ?\Throwable
    {
        return $this->outcome?->thrown();
    }
}
