<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Exception\InvalidSpecificationArgument;
use Rasuvaeff\Understudy\Expectation\ComputeAnswer;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Expectation\ReturnValue;
use Rasuvaeff\Understudy\Expectation\ThrowError;

/**
 * Configures what a stubbed call does.
 *
 * `TReturn` is the return type of the specified method. Plain Psalm and
 * PHPStan cannot infer it from the closure on their own — `understudy-psalm`
 * fills it in, and until then the parameter stays `mixed`, which is why the
 * template is declared here rather than added later: a published signature
 * cannot grow one without changing its contract.
 *
 * Not `final`, and the one class here that is not: `ExpectBuilder` extends it
 * to add the cardinality verbs, and the two are one fluent vocabulary rather
 * than two. For everyone else it is closed by contract, not by keyword:
 * subclassing is not supported, the `protected readonly Expectation` it
 * carries is an `@internal` type that may change in any release, and a subclass
 * reading it is on its own. The keyword is missing only because closing the
 * class would mean duplicating every action verb on `ExpectBuilder` — and a
 * `@final` tag is not an option either, since Psalm would then refuse
 * `ExpectBuilder` itself.
 *
 * @template TReturn
 *
 * @api
 */
class WhenBuilder
{
    /**
     * Which link of the action chain the next verb writes to.
     *
     * @var int<0, max>
     */
    private int $slot = 0;

    /**
     * @internal
     */
    public function __construct(protected readonly Expectation $expectation) {}

    /**
     * Returns each value in turn across successive calls, then keeps returning
     * the last one.
     *
     * @param TReturn ...$values
     */
    public function returns(mixed ...$values): static
    {
        $list = array_values($values);

        if ($list === []) {
            throw InvalidSpecificationArgument::noReturnValues();
        }

        // Several values ARE a chain: `returns($a, $b)` is exactly
        // `returns($a)->then()->returns($b)`. Keeping them as one action with
        // a position of its own would give the expectation two competing
        // notions of "the next call", and `returns($a, $b)->then()->returns($c)`
        // would step over $b without ever answering it.
        foreach ($list as $offset => $value) {
            $this->expectation->setAction(new ReturnValue($value), $this->slot + $offset);
        }

        $this->slot += count($list) - 1;

        return $this;
    }

    /**
     * Throws this exact instance on the call — the same object every time the
     * link answers, which is what a test holding a reference to it expects.
     */
    public function throws(\Throwable $error): static
    {
        $this->expectation->setAction(new ThrowError($error), $this->slot);

        return $this;
    }

    /**
     * Computes the return value for each matching call.
     *
     * @param callable(Invocation): TReturn $answer
     */
    public function answers(callable $answer): static
    {
        $this->expectation->setAction(new ComputeAnswer($answer), $this->slot);

        return $this;
    }

    /**
     * Opens the next link of the chain, so the verb that follows describes
     * what the *next* call does:
     *
     * ```php
     * when(fn () => $breaker->call($operation))
     *     ->returns('ok')
     *     ->then()->throws(new ConnectionLost());
     * ```
     *
     * Once the chain runs out, its last link keeps answering.
     */
    public function then(): static
    {
        $this->slot++;

        return $this;
    }

    /**
     * Makes the call count part of what `verifyAll()` checks. Without it a
     * stub is permission, not a claim.
     *
     * With one argument the count is exact; with two it is a range, and a
     * `null` maximum means no upper bound.
     *
     * @param int<0, max>      $minimum
     * @param int<0, max>|null $maximum
     */
    public function times(int $minimum, ?int $maximum = null): static
    {
        $this->expectation->setCardinality(
            func_num_args() === 1
                ? Cardinality::exactly($minimum)
                : Cardinality::between($minimum, $maximum),
        );

        return $this;
    }
}
