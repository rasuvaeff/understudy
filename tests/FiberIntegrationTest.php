<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;

/**
 * A test body that runs in a Fiber owns a context of its own — and a runner
 * adapter asks about the test from wherever it happens to stand, which is not
 * that Fiber.
 *
 * Every test here therefore asks from OUTSIDE the Fiber, on purpose. Asking
 * from inside proves only that a context can see itself, which was never in
 * question, and it is what an earlier version of this file did while claiming
 * otherwise.
 *
 * @internal
 */
#[Test]
#[CoversNothing]
final class FiberIntegrationTest
{
    #[AfterTest]
    public function dropWhateverTheFiberLeft(): void
    {
        Understudy::reset();
    }

    public function anUnmetExpectationInsideAFiberIsSeenFromOutside(): void
    {
        $fiber = new \Fiber(static function (): void {
            $double = Understudy::for(BookRepository::class);

            expect(static fn() => $double->find(123));
        });
        $fiber->start();

        try {
            Understudy::verifyAll();

            Assert::fail('Expected the Fiber body\'s unmet expectation to be reported');
        } catch (VerificationFailed $failure) {
            Assert::string($failure->getMessage())->contains('find(123)')->contains('never');
        }
    }

    #[ExpectNoAssertions]
    public function aSatisfiedExpectationInsideAFiberVerifiesFromOutside(): void
    {
        $fiber = new \Fiber(static function (): void {
            $double = Understudy::for(BookRepository::class);

            expect(static fn() => $double->find(123));
            $double->find(123);
        });
        $fiber->start();

        Understudy::verifyAll();
    }

    public function aFiberBodysDoublesMakeTheContextNotIdle(): void
    {
        Assert::true(Understudy::idle());

        $fiber = new \Fiber(static function (): void {
            Understudy::for(BookRepository::class);
        });
        $fiber->start();

        Assert::false(Understudy::idle());
    }

    public function resetFromOutsideDropsWhatAFiberCreated(): void
    {
        $fiber = new \Fiber(static function (): void {
            $double = Understudy::for(BookRepository::class);

            expect(static fn() => $double->find(123));
        });
        $fiber->start();

        Understudy::reset();

        Assert::true(Understudy::idle());
        // Nothing left to fail on: the unmet expectation went with the context.
        Understudy::verifyAll();
    }

    public function resetFromInsideAFiberAlsoDropsTheOuterFlowsDoubles(): void
    {
        // The shape Testo actually has: the adapter's teardown runs in one
        // Fiber while the body's understudies live in another that the assert
        // collector opened. A reset that only reached its own Fiber left them
        // to answer the next test.
        $inner = new \Fiber(static function (): void {
            Understudy::for(BookRepository::class);
        });
        $inner->start();

        $outer = new \Fiber(static function (): void {
            Understudy::reset();
        });
        $outer->start();

        Assert::true(Understudy::idle());
    }

    public function siblingFibersDoNotShareARecordingPhase(): void
    {
        // Isolation is what the per-Fiber context is for, and it survives the
        // accounting change: two bodies configuring at once must not see each
        // other's recording phase.
        $observed = [];

        $first = new \Fiber(static function (): void {
            $double = Understudy::for(BookRepository::class);
            expect(static fn() => $double->find(1));
            \Fiber::suspend();
            $double->find(1);
        });
        $second = new \Fiber(static function () use (&$observed): void {
            $double = Understudy::for(BookRepository::class);

            $observed[] = $double->find(2);
        });

        $first->start();
        $second->start();
        $first->resume();

        // The second Fiber's call answered from its own loose default rather
        // than being swallowed by the first Fiber's recording phase.
        Assert::same($observed, [null]);
    }
}
