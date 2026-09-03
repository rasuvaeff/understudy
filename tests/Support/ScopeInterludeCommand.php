<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Support;

use Rasuvaeff\PropertyTesting\StateMachine\Command;
use Rasuvaeff\Understudy\Exception\ForgottenDouble;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Understudy;

/**
 * Runs a whole nested `scope()` life — build an inner double, claim a call on
 * it, satisfy the claim, close — in the middle of the outer sequence.
 *
 * Three things the model predicts, and only a random interleaving exercises:
 *
 * - the close answers for the scope's own context and nothing else, so a
 *   self-contained scope closes clean at any point of the outer sequence,
 *   including one where the outer ledger's claims are violated right now;
 * - the outer ledger is neither settled nor forgiven by that close: a
 *   `verifyAll()` taken immediately afterwards still refuses exactly when the
 *   model says the outer claims are violated;
 * - the inner double dies with its context — a later call meets
 *   `ForgottenDouble` — and the outer model is untouched.
 */
final readonly class ScopeInterludeCommand implements Command
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

    /**
     * @return array{threw: bool, outerRefused: bool, innerRetired: bool, innerAnswer: ?Book}
     */
    #[\Override]
    public function run(mixed $model, mixed $system): array
    {
        \assert($system instanceof EngineHarness);

        $inner = null;
        $answer = null;
        $threw = false;

        try {
            Understudy::scope(static function () use (&$inner, &$answer): void {
                /** @var BookRepository $inner */
                $inner = Understudy::for(BookRepository::class);
                Understudy::expect(static fn(): ?Book => $inner->find(9))->returns(null);
                $answer = $inner->find(9);
            });
        } catch (VerificationFailed) {
            $threw = true;
        }

        // Taken after the close, not before: it is the proof that the scope
        // checked its own context without settling or forgiving the outer
        // one — the claims the close deliberately ignored are still standing.
        $outerRefused = false;

        try {
            Understudy::verifyAll();
        } catch (VerificationFailed) {
            $outerRefused = true;
        }

        \assert($inner instanceof BookRepository);

        $retired = false;

        try {
            $inner->find(9);
        } catch (ForgottenDouble) {
            $retired = true;
        }

        ++$system->cleanScopeCloses;

        return [
            'threw' => $threw,
            'outerRefused' => $outerRefused,
            'innerRetired' => $retired,
            'innerAnswer' => $answer,
        ];
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert($model instanceof EngineState);
        \assert(\is_array($result));

        // The inner cast is self-contained and satisfied, so the close has
        // nothing of its own to refuse — and the outer ledger, whatever state
        // it is in, is not a nested scope's to judge. What the outer ledger
        // holds is answered by the `verifyAll()` taken right after, unchanged.
        return $result['threw'] === false
            && $result['outerRefused'] === $model->claimsViolated()
            && $result['innerRetired'] === true
            && $result['innerAnswer'] === null;
    }

    #[\Override]
    public function __toString(): string
    {
        return 'scope(inner cast)';
    }
}
