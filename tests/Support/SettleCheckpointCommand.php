<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Understudy;

/**
 * Runs `checkpoint()`: verify the context, then settle it — satisfied claims
 * and accounted calls dropped, stubs and unaccounted calls carried into the
 * next phase. The model applies the same transition, but only when the check
 * passed; a violated claim means nothing was settled.
 */
final readonly class SettleCheckpointCommand implements Command
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

        return $model->claimsViolated() ? $model : $model->settled();
    }

    #[\Override]
    public function run(mixed $model, mixed $system): bool
    {
        \assert($system instanceof EngineHarness);

        $threw = false;

        try {
            Understudy::checkpoint();
        } catch (VerificationFailed) {
            $threw = true;
        }

        if (!$threw) {
            ++$system->settledCheckpoints;
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
        return 'checkpoint()';
    }
}
