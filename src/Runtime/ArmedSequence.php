<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Invocation;

/**
 * A protocol declared before the code under test runs, so that a call breaking
 * the order fails at that call — with the subject's own frame on top of the
 * stack — instead of in teardown.
 *
 * Totality is scoped to the doubles the protocol names: a call on one of them
 * is either the step due or something the test configured, and a double the
 * protocol does not name is invisible to it. Each step is due exactly once, in
 * order, which is what a cursor means; `expect(...)->ordered()` is the tool for
 * a relative order that tolerates repeats.
 *
 * @internal
 */
final class ArmedSequence
{
    /** @var int<0, max> */
    private int $cursor = 0;

    /**
     * @param non-empty-list<array{object, Expectation}> $steps
     */
    public function __construct(private readonly array $steps) {}

    public function watches(object $double): bool
    {
        foreach ($this->steps as [$owner, $step]) {
            if ($owner === $double) {
                return true;
            }
        }

        return false;
    }

    public function isComplete(): bool
    {
        return $this->cursor >= count($this->steps);
    }

    /**
     * The step the protocol is waiting on, or null once it has run out.
     */
    public function pending(): ?Expectation
    {
        return $this->steps[$this->cursor][1] ?? null;
    }

    /**
     * Position of the step due, counted from one for a reader.
     *
     * @return positive-int
     */
    public function position(): int
    {
        return $this->cursor + 1;
    }

    /**
     * @return positive-int
     */
    public function length(): int
    {
        return count($this->steps);
    }

    /**
     * How the protocol reads back, one rendered step per entry.
     *
     * @return non-empty-list<non-empty-string>
     */
    public function describe(): array
    {
        return array_map(
            static fn(array $step): string => $step[1]->describe(),
            $this->steps,
        );
    }

    /**
     * Offers one call to the protocol, advancing it when the call is the step
     * due. Called before anything answers the call: a call refused here must
     * not have been counted by an expectation or answered by an action.
     */
    public function offer(object $double, Invocation $invocation): SequenceVerdict
    {
        if (!$this->watches($double)) {
            return SequenceVerdict::NotWatched;
        }

        if (!$this->isComplete()) {
            [$owner, $step] = $this->steps[$this->cursor];

            if ($owner === $double && self::accepts($step, $invocation)) {
                ++$this->cursor;

                return SequenceVerdict::Advanced;
            }
        }

        return $this->isAnyStep($double, $invocation)
            ? SequenceVerdict::OutOfTurn
            : SequenceVerdict::NotAStep;
    }

    /**
     * A step already satisfied, or one still ahead: either way the call is a
     * protocol call arriving at the wrong moment, and saying so is the whole
     * point of arming the protocol.
     */
    private function isAnyStep(object $double, Invocation $invocation): bool
    {
        foreach ($this->steps as [$owner, $step]) {
            if ($owner === $double && self::accepts($step, $invocation)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Matching runs here during dispatch, inside the code under test. A
     * matcher that breaks must not become the failure the test reads: it
     * counts as one that did not match, exactly as it does when a refusal is
     * being rendered.
     */
    private static function accepts(Expectation $step, Invocation $invocation): bool
    {
        try {
            return $step->matches($invocation->method, $invocation->args);
        } catch (\Throwable) {
            return false;
        }
    }
}
