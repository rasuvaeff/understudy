<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Understudy;

/**
 * Runs `verifyAll()` — every claim in the context checked against its
 * cardinality — and observes whether the engine accepted. Nothing settles:
 * the model is unchanged whether the check passed or failed.
 */
final readonly class VerifyEverythingCommand implements Command
{
    #[\Override]
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    #[\Override]
    public function nextState(mixed $model): EngineState
    {
        \assert($model instanceof EngineState);

        return $model;
    }

    #[\Override]
    public function run(mixed $model, mixed $system): bool
    {
        \assert($system instanceof EngineHarness);

        $threw = false;

        try {
            Understudy::verifyAll();
        } catch (VerificationFailed) {
            $threw = true;
        }

        return $threw;
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert($model instanceof EngineState);
        \assert(\is_bool($result));

        return $result === $model->claimsViolated();
    }

    #[\Override]
    public function __toString(): string
    {
        return 'verifyAll()';
    }
}
