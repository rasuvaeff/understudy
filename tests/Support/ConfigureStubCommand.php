<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Understudy\WhenBuilder;

use function Rasuvaeff\Understudy\when;

/**
 * Registers a `when()` stub on the double. The answer title encodes the
 * registration index from the pre-state model, so a dispatch later in the
 * sequence names exactly which specification answered it.
 */
final readonly class ConfigureStubCommand implements Command
{
    public function __construct(
        private bool $anyArgument,
        private int $literalId,
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

        return $model->withSpec(new EngineSpec(
            anyArgument: $this->anyArgument,
            literalId: $this->literalId,
            isClaim: false,
            answerTitle: 's' . count($model->specs),
        ));
    }

    #[\Override]
    public function run(mixed $model, mixed $system): int
    {
        \assert($model instanceof EngineState);
        \assert($system instanceof EngineHarness);

        /** @var WhenBuilder<mixed> $builder */
        $builder = when(fn() => $system->double->find($this->argument()));
        $builder->returns(new Book('s' . count($model->specs)));

        // Recording must not itself dispatch: the log the model knows about
        // is still the whole log.
        return count(Understudy::calls(fn() => $system->double->find(Arg::any())));
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert($model instanceof EngineState);
        \assert(\is_int($result));

        return $result === $model->callCount();
    }

    #[\Override]
    public function __toString(): string
    {
        return 'stub(find(' . $this->describeArgument() . '))';
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
