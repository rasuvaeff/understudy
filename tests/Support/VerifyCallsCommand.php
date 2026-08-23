<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Understudy;

/**
 * Runs an explicit `verify()` over the whole log and observes whether the
 * engine accepted the cardinality. The model predicts acceptance from its
 * own count of matching calls; on acceptance every matching call becomes
 * accounted for, which later `nothingElse` commands go on to notice.
 */
final readonly class VerifyCallsCommand implements Command
{
    public function __construct(
        private bool $anyArgument,
        private int $literalId,
        private int $minimum,
        private ?int $maximum,
    ) {}

    #[\Override]
    public function preCondition(mixed $model): bool
    {
        return true;
    }

    #[\Override]
    public function nextState(mixed $model): EngineState
    {
        \assert($model instanceof EngineState);

        $matches = $model->matchingCallCount($this->anyArgument, $this->literalId);
        $accepted = $this->accepts($matches);

        return $accepted ? $model->withVerified($this->anyArgument, $this->literalId) : $model;
    }

    #[\Override]
    public function run(mixed $model, mixed $system): array
    {
        \assert($model instanceof EngineState);
        \assert($system instanceof EngineHarness);

        $threw = false;

        try {
            Understudy::verify(
                fn() => $system->double->find($this->argument()),
                minimum: $this->minimum,
                maximum: $this->maximum,
            );
        } catch (VerificationFailed) {
            $threw = true;
        }

        if ($threw) {
            ++$system->verifiesFailed;
        } else {
            ++$system->verifiesPassed;
        }

        return [$threw, $this->observedLogSize($system)];
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert($model instanceof EngineState);
        \assert(\is_array($result));

        [$threw, $logSize] = $result;

        $predicted = !$this->accepts($model->matchingCallCount($this->anyArgument, $this->literalId));

        return $threw === $predicted && $logSize === $model->callCount();
    }

    #[\Override]
    public function __toString(): string
    {
        return sprintf(
            'verify(find(%s), %s)',
            $this->describeArgument(),
            $this->maximum === null ? "at least {$this->minimum}" : "{$this->minimum}..{$this->maximum}",
        );
    }

    private function accepts(int $matches): bool
    {
        return $matches >= $this->minimum
            && ($this->maximum === null || $matches <= $this->maximum);
    }

    private function argument(): mixed
    {
        return $this->anyArgument ? Arg::any() : $this->literalId;
    }

    private function observedLogSize(EngineHarness $system): int
    {
        return count(Understudy::calls(fn() => $system->double->find(Arg::any())));
    }

    private function describeArgument(): string
    {
        return $this->anyArgument ? 'any' : (string) $this->literalId;
    }
}
