<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\ForgottenDouble;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Runtime\RuntimeContext;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

/**
 * `lastCall()` and `forget()` — the two operations dogfooding on
 * yii3-correlation-id showed missing: a null-safe read of the newest matching
 * call, and a deliberate retirement of a replaced double.
 */
#[Test]
#[Covers(Understudy::class)]
#[Covers(Runtime::class)]
#[Covers(RuntimeContext::class)]
#[Covers(Invocation::class)]
#[Covers(ForgottenDouble::class)]
#[Covers(InvalidCallSpecification::class)]
#[Covers(VerificationFailed::class)]
final class LastCallAndForgetTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    public function lastCallAnswersNullWhenNothingMatched(): void
    {
        $repository = Understudy::for(BookRepository::class);

        Assert::null(Understudy::lastCall(fn() => $repository->find(1)));
    }

    public function lastCallAnswersTheNewestMatchingCall(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->find(Arg::any()))->returns(new Book('first'), new Book('second'));

        $repository->find(1);
        $repository->find(2);

        Assert::same(Understudy::lastCall(fn() => $repository->find(Arg::any()))?->args, [2]);
    }

    public function lastCallIgnoresCallsToOtherMethods(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->find(Arg::any()))->returns(new Book('title'));

        $repository->find(1);
        $repository->count();

        $book = new Book('title');

        Assert::null(Understudy::lastCall(fn() => $repository->save($book)));
    }

    public function lastCallIgnoresCallsWithOtherArguments(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->find(Arg::any()))->returns(new Book('title'));

        $repository->find(7);

        Assert::null(Understudy::lastCall(fn() => $repository->find(8)));
    }

    #[ExpectNoAssertions]
    public function aForgottenDoubleIsInvisibleToStrictStubs(): void
    {
        $replaced = Understudy::for(BookRepository::class);
        when(fn() => $replaced->find(Arg::any()))->returns(new Book('title'));
        Understudy::forget($replaced);

        // The strict-stubs verdict must name nothing: the stub belonged to a
        // double the test retired, and reporting it would be a failure about
        // code the test no longer runs.
        Understudy::verifyAll(strictStubs: true);
    }

    public function aReplacedDoubleWithoutForgetFailsStrictStubs(): void
    {
        $replaced = Understudy::for(BookRepository::class);
        when(fn() => $replaced->find(Arg::any()))->returns(new Book('title'));

        $current = Understudy::for(BookRepository::class);
        when(fn() => $current->find(Arg::any()))->returns(new Book('title'));
        $current->find(1);

        $failed = false;

        try {
            Understudy::verifyAll(strictStubs: true);
        } catch (VerificationFailed) {
            $failed = true;
        }

        Assert::true($failed);
    }

    public function aForgottenDoubleRefusesVerificationWithTheReason(): void
    {
        $replaced = Understudy::for(BookRepository::class);
        when(fn() => $replaced->find(Arg::any()))->returns(new Book('title'));
        Understudy::forget($replaced);

        // Asking anything about a retired double — its calls, its accounting —
        // is a question about an object the test replaced; say so rather than
        // silently passing or reporting a closure problem that is not there.
        Expect::exception(ForgottenDouble::class)->withMessage(
            'This understudy was retired with Understudy::forget() and can no longer be asked '
            . 'anything — not calls, not verification. A replacement built afterwards is a '
            . 'different object; ask that one.',
        );

        Understudy::nothingElse($replaced);
    }

    public function callingAForgottenDoubleNamesForget(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn() => $repository->find(Arg::any()))->returns(new Book('title'));
        Understudy::forget($repository);

        Expect::exception(ForgottenDouble::class)->withMessage(
            "This understudy was retired with Understudy::forget(), but `find()` was called on it.\n"
            . 'A forgotten double is gone for good; build a new one instead of calling this one.',
        );

        $repository->find(1);
    }

    public function forgettingANonDoubleIsRefusedByName(): void
    {
        Expect::exception(InvalidCallSpecification::class)->withMessage(
            'Understudy::forget() expects an understudy created by Understudy::for(). '
            . 'This object is not one.',
        );

        Understudy::forget(new \stdClass());
    }

    /**
     * The object IS a double, so the answer is the one every other facade
     * gives for a retired one — not «this object is not one», which sent the
     * reader looking for a mistake they had not made.
     */
    public function forgettingADoubleTwiceSaysItWasRetired(): void
    {
        $repository = Understudy::for(BookRepository::class);
        Understudy::forget($repository);

        Expect::exception(ForgottenDouble::class)->withMessage(
            'This understudy was retired with Understudy::forget() and can no longer be asked '
            . 'anything — not calls, not verification. A replacement built afterwards is a '
            . 'different object; ask that one.',
        );

        Understudy::forget($repository);
    }

    public function forgettingADoubleAfterResetSaysItIsGone(): void
    {
        $repository = Understudy::for(BookRepository::class);
        Understudy::reset();

        Expect::exception(ForgottenDouble::class)->withMessage(
            "This understudy is no longer known to Understudy, but `forget()` was called on it.\n"
            . 'It was created before a reset(); create doubles inside the test that uses them '
            . 'rather than sharing one across tests.',
        );

        Understudy::forget($repository);
    }
}
