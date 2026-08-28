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
 * Two things the model predicts, and only a random interleaving exercises:
 *
 * - closing a scope verifies EVERY live context, the outer one included, so
 *   the close throws exactly when the outer ledger's claims are violated at
 *   that moment — accounting is wider than isolation;
 * - whether the close threw or not, the inner double dies with its context
 *   (a later call meets `ForgottenDouble`) and the outer model is untouched:
 *   a scope checks, it never settles.
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
     * @return array{threw: bool, innerRetired: bool, innerAnswer: ?Book}
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

        \assert($inner instanceof BookRepository);

        $retired = false;

        try {
            $inner->find(9);
        } catch (ForgottenDouble) {
            $retired = true;
        }

        if ($threw) {
            ++$system->refusedScopeCloses;
        } else {
            ++$system->cleanScopeCloses;
        }

        return ['threw' => $threw, 'innerRetired' => $retired, 'innerAnswer' => $answer];
    }

    #[\Override]
    public function postCondition(mixed $model, mixed $result): bool
    {
        \assert($model instanceof EngineState);
        \assert(\is_array($result));

        // The inner cast is self-contained and satisfied, so the close throws
        // for exactly one reason: the OUTER ledger's claims were violated when
        // the scope's verify swept every live context.
        return $result['threw'] === $model->claimsViolated()
            && $result['innerRetired'] === true
            && $result['innerAnswer'] === null;
    }

    #[\Override]
    public function __toString(): string
    {
        return 'scope(inner cast)';
    }
}
