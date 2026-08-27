<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\ConflictingExpectation;
use Rasuvaeff\Understudy\Understudy;

use function Rasuvaeff\Understudy\expect;

/**
 * Declares an `expect()` claim on the double with explicit cardinality.
 * Claims carry no action on purpose: a matched claim falls through to the
 * mode's own answer while still counting the call and accounting for it,
 * which is precisely the pairing the ledger has to keep straight.
 */
final readonly class ConfigureExpectCommand implements Command
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

        if ($model->conflicts($this->anyArgument, $this->literalId, incomingIsClaim: true)) {
            // The engine refuses the registration, so the model keeps none.
            return $model;
        }

        return $model->withSpec(new EngineSpec(
            anyArgument: $this->anyArgument,
            literalId: $this->literalId,
            isClaim: true,
            answerTitle: '',
            minimum: $this->minimum,
            maximum: $this->maximum,
        ));
    }

    #[\Override]
    public function run(mixed $model, mixed $system): array
    {
        \assert($model instanceof EngineState);
        \assert($system instanceof EngineHarness);

        $refused = false;

        try {
            expect(fn() => $system->double->find($this->argument()))
                ->times(minimum: $this->minimum, maximum: $this->maximum);
        } catch (ConflictingExpectation) {
            $refused = true;
            ++$system->refusedRegistrations;
        }

        return [
            count(Understudy::calls(fn() => $system->double->find(Arg::any()))),
            $refused,
        ];
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert($model instanceof EngineState);
        \assert(\is_array($result));

        [$logSize, $refused] = $result;

        // The engine must refuse exactly the registrations the model calls
        // conflicting — no silent degradation, no spurious refusal.
        return $logSize === $model->callCount()
            && $refused === $model->conflicts($this->anyArgument, $this->literalId, incomingIsClaim: true);
    }

    #[\Override]
    public function __toString(): string
    {
        return sprintf(
            'expect(find(%s), %s)',
            $this->describeArgument(),
            $this->maximum === null ? "at least {$this->minimum}" : "{$this->minimum}..{$this->maximum}",
        );
    }

    private function argument(): mixed
    {
        return $this->anyArgument ? Arg::any() : $this->literalId;
    }

    private function describeArgument(): string
    {
        return $this->anyArgument ? 'any' : (string) $this->literalId;
    }
}
