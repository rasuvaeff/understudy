<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

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

    public function __construct()
    {
        /** @var \SplObjectStorage<object, DoubleState> $doubles */
        $doubles = new \SplObjectStorage();
        $this->doubles = $doubles;
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
     * @return positive-int
     */
    public function nextSequence(): int
    {
        return ++$this->sequence;
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
