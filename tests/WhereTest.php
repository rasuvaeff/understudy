<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Exception\StrictModeViolation;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Tests\Fixture\Ref\Registry;
use Rasuvaeff\Understudy\Tests\Fixture\VariadicSink;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Understudy\WhenBuilder;
use Testo\Assert;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

/**
 * `where()`: a predicate over the whole argument list — the joint constraint
 * no positional matcher can express.
 */
#[Test]
#[Covers(WhenBuilder::class)]
#[Covers(Expectation::class)]
#[Covers(InvalidCallSpecification::class)]
#[Covers(Understudy::class)]
final class WhereTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    public function aGuardNarrowsAStub(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->tag('alpha', Arg::any()))
            ->where(fn(string $name, int $weight) => $weight >= 10)
            ->returns('heavy');

        Assert::same($repository->tag('alpha', 12), 'heavy');

        // The positions matched and the joint constraint did not: the stub
        // does not answer, and loose mode's own default does.
        Assert::same($repository->tag('alpha', 2), '');
    }

    public function aRefusedGuardFallsThroughToABroaderStub(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->tag(Arg::any(), Arg::any()))->returns('any');
        when(fn() => $repository->tag('alpha', Arg::any()))
            ->where(fn(string $name, int $weight) => $weight > 5)
            ->returns('heavy-alpha');

        Assert::same($repository->tag('alpha', 9), 'heavy-alpha');
        Assert::same($repository->tag('beta', 9), 'any');

        // The narrow registration exists and its positions accepted, but its
        // guard refused — the broad fallback answers, as layering promises.
        Assert::same($repository->tag('alpha', 1), 'any');
    }

    #[ExpectNoAssertions]
    public function expectHonoursTheGuard(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->tag('alpha', Arg::any()))
            ->where(fn(string $name, int $weight) => $weight >= 10)
            ->times(1);

        $repository->tag('alpha', 2);
        $repository->tag('alpha', 12);

        // One call satisfied the guard; the refused one was never the
        // expected call, so the claim is met.
        Understudy::verifyAll();
    }

    public function anExpectationOnlyTheGuardRefusedIsUnmet(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->tag('alpha', Arg::any()))
            ->where(fn(string $name, int $weight) => $weight >= 10)
            ->times(1);

        Expect::exception(VerificationFailed::class)->withMessageContaining('where(…)');

        Understudy::verifyAll();
    }

    #[ExpectNoAssertions]
    public function verifyCountsOnlyTheCallsTheGuardAccepted(): void
    {
        $repository = Understudy::for(BookRepository::class);

        $repository->tag('alpha', 2);
        $repository->tag('alpha', 12);
        $repository->tag('alpha', 20);

        verify(
            fn() => $repository->tag('alpha', Arg::any()),
            times: 2,
            where: fn(string $name, int $weight) => $weight >= 10,
        );
    }

    public function aSecondWhereIsRefusedWhereItIsWritten(): void
    {
        $repository = Understudy::for(BookRepository::class);

        Expect::exception(InvalidCallSpecification::class)->withMessage(
            '`tag` already carries a `where()` guard (`where(…)`). '
            . 'One call, one guard: put both conditions in the one closure.',
        );

        when(fn() => $repository->tag('alpha'))
            ->where(fn(string $name) => true)
            ->where(fn(string $name) => false);
    }

    public function aSecondWhereNamesTheFirstGuardsOwnDescription(): void
    {
        $repository = Understudy::for(BookRepository::class);

        Expect::exception(InvalidCallSpecification::class)->withMessage(
            '`tag` already carries a `where()` guard (`where(weight >= 10)`). '
            . 'One call, one guard: put both conditions in the one closure.',
        );

        when(fn() => $repository->tag('alpha'))
            ->where(fn(string $name, int $weight = 10) => $weight >= 10, description: 'where(weight >= 10)')
            ->where(fn(string $name) => false);
    }

    public function theGuardRendersInAFailureMessage(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->tag('alpha', Arg::any()))
            ->where(fn(string $name, int $weight) => $weight >= 10)
            ->times(1);

        Expect::exception(VerificationFailed::class)
            ->withMessageContaining("tag('alpha', any()) where(…)");

        Understudy::verifyAll();
    }

    public function aDescribedGuardRendersItsOwnName(): void
    {
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->tag('alpha', Arg::any()))
            ->where(
                fn(string $name, int $weight) => $weight >= 10,
                description: 'where(weight >= 10)',
            )
            ->times(1);

        Expect::exception(VerificationFailed::class)
            ->withMessageContaining("tag('alpha', any()) where(weight >= 10)");

        Understudy::verifyAll();
    }

    public function aRefusedGuardIsMarkedInAStrictRefusal(): void
    {
        $repository = Understudy::for(BookRepository::class);

        Understudy::strict($repository);

        when(fn() => $repository->tag('alpha', Arg::any()))
            ->where(fn(string $name, int $weight) => $weight >= 10)
            ->returns('heavy');

        // The positions accepted the call; the joint constraint refused it.
        // The message has to say so about the guard, not about a position.
        Expect::exception(StrictModeViolation::class)
            ->withMessageContaining("tag('alpha', any()) *where(…)*");

        $repository->tag('alpha', 2);
    }

    public function theByReferenceSlotWalkHonoursTheGuard(): void
    {
        $registry = Understudy::for(Registry::class);

        when(fn() => $registry->row(Arg::any()))
            ->where(fn(int $id) => $id > 0)
            ->returns(['ok' => true]);

        // The slot decision walks the expectations the way dispatch will, so
        // a call the guard refuses must look unconfigured to it: the slot
        // keeps the mode's own seed rather than the action's value.
        $refused = &$registry->row(-1);
        $refused['written'] = true;

        Assert::same($refused, ['written' => true]);
    }

    public function aGuardRefusingAZeroArgumentCallIsAMismatch(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn() => $repository->count())
            ->where(fn() => false)
            ->returns(7);

        // The call carried no arguments and the guard refused it anyway: the
        // stub does not answer, and the mode's int default does.
        Assert::same($repository->count(), 0);
    }

    public function aGuardCanReadTheVariadicTail(): void
    {
        $sink = Understudy::for(VariadicSink::class);

        when(fn() => $sink->write(Arg::any(), Arg::rest()))
            ->where(fn(string $channel, int ...$values) => $values === [1, 2, 3])
            ->returns(true);

        Assert::true($sink->write('log', 1, 2, 3));

        // Same channel, same shape, different tail: the guard refused, and
        // loose mode's bool default answers instead.
        Assert::false($sink->write('log', 3, 2, 1));
    }
}
