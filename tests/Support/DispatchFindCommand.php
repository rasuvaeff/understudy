<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Understudy;

/**
 * Dispatches one real call against the double and observes what answered.
 * The oracle is the model: the last matching specification decides the
 * answer — its title when it carries an action, the loose default (`null`
 * for `?Book`) when it does not or none matches — and the log grows by
 * exactly one entry, accounted precisely when a claim matched.
 */
final readonly class DispatchFindCommand implements Command
{
    public function __construct(
        private int $id,
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

        return $model->afterDispatch($this->id);
    }

    #[\Override]
    public function run(mixed $model, mixed $system): array
    {
        \assert($model instanceof EngineState);
        \assert($system instanceof EngineHarness);

        /** @var Book|null $answer */
        $answer = $system->double->find($this->id);

        $answering = $model->answerFor($this->id);

        if ($answering === null) {
            ++$system->unmatchedDefaults;
        } elseif ($answering->hasAction()) {
            ++$system->stubAnswers;
        } else {
            ++$system->countedClaims;
        }

        return [
            $answer === null ? null : $answer->title,
            count(Understudy::calls(fn() => $system->double->find(Arg::any()))),
        ];
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert($model instanceof EngineState);
        \assert(\is_array($result));

        [$title, $logSize] = $result;

        if ($logSize !== $model->callCount() + 1) {
            return false;
        }

        $answering = $model->answerFor($this->id);
        $expectedTitle = $answering !== null && $answering->hasAction() ? $answering->answerTitle : null;

        return $title === $expectedTitle;
    }

    #[\Override]
    public function __toString(): string
    {
        return "find({$this->id})";
    }
}
