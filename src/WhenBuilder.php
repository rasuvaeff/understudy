<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Expectation\ComputeAnswer;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Expectation\ReturnValues;
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
 * @template TReturn
 *
 * @api
 */
final readonly class WhenBuilder
{
    /**
     * @internal
     */
    public function __construct(private Expectation $expectation) {}

    /**
     * Returns each value in turn across successive calls, then keeps returning
     * the last one.
     *
     * @param TReturn ...$values
     */
    public function returns(mixed ...$values): self
    {
        $list = array_values($values);

        if ($list === []) {
            throw new \InvalidArgumentException('returns() needs at least one value');
        }

        $this->expectation->setAction(new ReturnValues($list));

        return $this;
    }

    public function throws(\Throwable $error): self
    {
        $this->expectation->setAction(new ThrowError($error));

        return $this;
    }

    /**
     * @param callable(Invocation): TReturn $answer
     */
    public function answers(callable $answer): self
    {
        $this->expectation->setAction(new ComputeAnswer($answer));

        return $this;
    }
}
