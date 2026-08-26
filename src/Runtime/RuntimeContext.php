<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

use Rasuvaeff\Understudy\Defaults\DefaultFactories;
use Rasuvaeff\Understudy\Invocation;

/**
 * One test's worth of state. The main flow and each Fiber own a separate
 * context, so sibling fibers never share a recording phase, a call log or a
 * sequence counter.
 *
 * @internal
 */
final class RuntimeContext
{
    /**
     * Depth, not a boolean: a nested recording phase must not switch the
     * enclosing one off when it unwinds.
     *
     * @var int<0, max>
     */
    private int $recordingDepth = 0;

    /** @var \SplObjectStorage<object, DoubleState> */
    private \SplObjectStorage $doubles;

    /** @var int<0, max> */
    private int $sequence = 0;

    /** @var int<0, max> */
    private int $declarations = 0;

    /**
     * The protocol armed in this context, if any. One at a time: two of them
     * naming the same double would each judge every call on it and could
     * disagree about the same call.
     */
    public ?ArmedSequence $armed = null;

    private bool $retired = false;

    private readonly DefaultFactories $defaultFactories;

    public function __construct()
    {
        /** @var \SplObjectStorage<object, DoubleState> $doubles */
        $doubles = new \SplObjectStorage();
        $this->doubles = $doubles;
        $this->defaultFactories = new DefaultFactories();
    }

    /**
     * The loose-default factories this context knows. Per context on purpose:
     * sibling Fibers do not see each other's registrations, and `reset()` drops
     * them with the rest of the test rather than leaking into the next one.
     */
    public function defaultFactories(): DefaultFactories
    {
        return $this->defaultFactories;
    }

    public function arm(ArmedSequence $sequence): void
    {
        $this->armed = $sequence;
    }

    public function disarm(): void
    {
        $this->armed = null;
    }

    public function isRecording(): bool
    {
        return $this->recordingDepth > 0;
    }

    public function beginRecording(): void
    {
        $this->recordingDepth++;
    }

    public function endRecording(): void
    {
        if ($this->recordingDepth > 0) {
            $this->recordingDepth--;
        }
    }

    /**
     * Whether this context was torn down. A retired context keeps answering
     * "I owned this double" so that a call on a stale one can say what
     * happened to it, and answers nothing else.
     */
    public function isRetired(): bool
    {
        return $this->retired;
    }

    /**
     * One write, rather than one per double it holds. Teardown runs after
     * every test, and what it costs is paid by every test in the suite.
     *
     * What it does NOT do is drop the registry: a context still on a Fiber's
     * stack keeps answering for its own doubles, and only cross-context
     * lookups stop finding it. Clearing here killed a sibling Fiber's doubles
     * when the main flow reset.
     */
    public function retire(): void
    {
        $this->retired = true;
    }

    public function register(object $double, DoubleState $state): void
    {
        $this->doubles[$double] = $state;
    }

    public function knows(object $double): bool
    {
        return isset($this->doubles[$double]);
    }

    public function stateOf(object $double): ?DoubleState
    {
        return $this->doubles[$double] ?? null;
    }

    /**
     * Drops the double's state. Verification and reset stop seeing it; a call
     * on the object afterwards meets the forgotten-double guard in Runtime.
     */
    public function forget(object $double): void
    {
        unset($this->doubles[$double]);
    }

    /**
     * @return list<DoubleState>
     */
    public function allStates(): array
    {
        $states = [];

        foreach ($this->doubles as $double) {
            $states[] = $this->doubles[$double];
        }

        return $states;
    }

    /**
     * @return list<object>
     */
    public function allDoubles(): array
    {
        $doubles = [];

        foreach ($this->doubles as $double) {
            $doubles[] = $double;
        }

        return $doubles;
    }

    /**
     * @return positive-int
     */
    public function nextSequence(): int
    {
        return ++$this->sequence;
    }

    /**
     * Counts expectations as they are declared, across every understudy of
     * this context, so an ordering claim can be read in the order it was
     * written even when two doubles are interleaved.
     *
     * @return positive-int
     */
    public function nextDeclaration(): int
    {
        return ++$this->declarations;
    }

    /**
     * Every call on every understudy of this context, in the order they
     * happened. Verifying a sequence needs one order across doubles, so the
     * ordering lives here rather than in each DoubleState.
     *
     * @return list<Invocation>
     */
    public function globalLog(): array
    {
        $log = [];

        foreach ($this->allStates() as $state) {
            foreach ($state->callLog() as $invocation) {
                $log[] = $invocation;
            }
        }

        usort($log, static fn(Invocation $a, Invocation $b): int => $a->sequence <=> $b->sequence);

        return $log;
    }
}
