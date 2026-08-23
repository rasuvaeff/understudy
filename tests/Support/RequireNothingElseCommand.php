<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Understudy;

/**
 * Runs `nothingElse()` and observes whether the engine found an unaccounted
 * call. The model knows exactly which log entries something accounted for —
 * a matched claim or a successful explicit verify — so this pins the whole
 * accounting chain end to end.
 */
final readonly class RequireNothingElseCommand implements Command
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
            Understudy::nothingElse($system->double);
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

        return $result === $model->hasUnaccountedCalls();
    }

    #[\Override]
    public function __toString(): string
    {
        return 'nothingElse()';
    }
}
