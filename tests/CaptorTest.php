<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Captor;
use Rasuvaeff\Understudy\Exception\MatcherLeaked;
use Rasuvaeff\Understudy\Exception\NothingCaptured;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Matcher\Capturing;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Runtime\RuntimeContext;
use Rasuvaeff\Understudy\Tests\Fixture\Capt\DeliveryOptions;
use Rasuvaeff\Understudy\Tests\Fixture\Capt\UrlStore;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

/**
 * `Arg::captor()`: the typed replacement for reading `args[N]` out of the call
 * log.
 */
#[Test]
#[Covers(Captor::class)]
#[Covers(Understudy::class)]
#[Covers(Capturing::class)]
#[Covers(Arg::class)]
#[Covers(Expectation::class)]
#[Covers(Runtime::class)]
#[Covers(RuntimeContext::class)]
#[Covers(NothingCaptured::class)]
final class CaptorTest
{
    private UrlStore $store;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->store = Understudy::for(UrlStore::class);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    // --- Capturing at dispatch ----------------------------------------------

    public function aStubCapturesTheArgumentOfAMatchedCall(): void
    {
        $options = Arg::captor(DeliveryOptions::class);
        when(fn(): ?string => $this->store->temporaryUrl(Arg::any(), Arg::any(), $options->capture()))
            ->returns('https://example.test/report');

        $this->store->temporaryUrl('report.pdf', new \DateTimeImmutable(), new DeliveryOptions('my report.pdf'));

        Assert::same($options->last()->downloadName, 'my report.pdf');
    }

    public function anExpectationCapturesTheSameWay(): void
    {
        $options = Arg::captor(DeliveryOptions::class);
        expect(fn(): ?string => $this->store->temporaryUrl(Arg::any(), Arg::any(), $options->capture()))
            ->returns('https://example.test/report');

        $this->store->temporaryUrl('report.pdf', new \DateTimeImmutable(), new DeliveryOptions('one.pdf'));

        Assert::same($options->last()->downloadName, 'one.pdf');
        Understudy::verifyAll();
    }

    public function allAnswersEveryCapturedValueInCallOrder(): void
    {
        $options = Arg::captor(DeliveryOptions::class);
        when(fn(): ?string => $this->store->temporaryUrl(Arg::any(), Arg::any(), $options->capture()))
            ->returns('url');

        $now = new \DateTimeImmutable();
        $this->store->temporaryUrl('a.pdf', $now, new DeliveryOptions('first.pdf'));
        $this->store->temporaryUrl('b.pdf', $now, new DeliveryOptions('second.pdf'));

        Assert::same(
            array_map(static fn(DeliveryOptions $o): string => $o->downloadName, $options->all()),
            ['first.pdf', 'second.pdf'],
        );
        Assert::same($options->last()->downloadName, 'second.pdf');
    }

    /**
     * The typed form matches like `Arg::instanceOf()`: a call carrying
     * something else falls through to the mode's default, and nothing is
     * captured from it.
     */
    public function aTypedCaptorDoesNotMatchAnotherType(): void
    {
        $options = Arg::captor(DeliveryOptions::class);
        when(fn(): ?string => $this->store->temporaryUrl(Arg::any(), Arg::any(), $options->capture()))
            ->returns('url');

        Assert::null($this->store->temporaryUrl('a.pdf', new \DateTimeImmutable(), null));
        Assert::same($options->all(), []);
    }

    public function anUntypedCaptorCapturesAnythingIncludingNull(): void
    {
        $payload = Arg::captor();
        when(fn() => $this->store->note($payload->capture()))->returns(null);

        $this->store->note(null);
        $this->store->note(['k' => 1]);

        Assert::same($payload->all(), [null, ['k' => 1]]);
    }

    /**
     * The commit point is the whole specification, not the captor's own
     * position: a matcher is asked about calls whose other arguments then
     * reject them, and recording there would capture arguments of calls the
     * specification never named.
     */
    public function nothingIsCapturedFromACallTheSpecificationRejected(): void
    {
        $options = Arg::captor(DeliveryOptions::class);
        when(fn(): ?string => $this->store->temporaryUrl('expected.pdf', Arg::any(), $options->capture()))
            ->returns('url');

        $this->store->temporaryUrl('other.pdf', new \DateTimeImmutable(), new DeliveryOptions('lost.pdf'));

        Assert::same($options->all(), []);
    }

    public function twoCaptorsCaptureTwoPositionsOfOneCall(): void
    {
        $paths = Arg::captor();
        $options = Arg::captor(DeliveryOptions::class);
        when(fn(): ?string => $this->store->temporaryUrl($paths->capture(), Arg::any(), $options->capture()))
            ->returns('url');

        $this->store->temporaryUrl('report.pdf', new \DateTimeImmutable(), new DeliveryOptions('r.pdf'));

        Assert::same($paths->last(), 'report.pdf');
        Assert::same($options->last()->downloadName, 'r.pdf');
    }

    // --- Capturing at verification ------------------------------------------

    public function verifyCapturesFromTheCallsItClaimed(): void
    {
        $this->store->temporaryUrl('a.pdf', new \DateTimeImmutable(), new DeliveryOptions('first.pdf'));
        $this->store->temporaryUrl('b.pdf', new \DateTimeImmutable(), new DeliveryOptions('second.pdf'));

        $options = Arg::captor(DeliveryOptions::class);
        verify(fn(): ?string => $this->store->temporaryUrl(Arg::any(), Arg::any(), $options->capture()), times: 2);

        Assert::same($options->last()->downloadName, 'second.pdf');
    }

    public function aFailedVerifyCapturesNothing(): void
    {
        $this->store->temporaryUrl('a.pdf', new \DateTimeImmutable(), new DeliveryOptions('first.pdf'));

        $options = Arg::captor(DeliveryOptions::class);

        try {
            verify(fn(): ?string => $this->store->temporaryUrl(Arg::any(), Arg::any(), $options->capture()), times: 2);
        } catch (\Throwable) {
            // The count was wrong on purpose.
        }

        Assert::same($options->all(), []);
    }

    // --- Lifetime -----------------------------------------------------------

    public function lastRefusesWhenNothingWasCaptured(): void
    {
        $options = Arg::captor(DeliveryOptions::class);

        Expect::exception(NothingCaptured::class)->withMessage(
            'The captor for `' . DeliveryOptions::class . "` has captured nothing: "
            . "no matched call carried a value through its capture() argument.\n"
            . 'Make the call happen first — or read all(), which answers an empty list.',
        );

        $options->last();
    }

    public function anUntypedCaptorNamesNoClassInTheRefusal(): void
    {
        Expect::exception(NothingCaptured::class)
            ->withMessageContaining('The captor has captured nothing');

        Arg::captor()->last();
    }

    /**
     * Every captor of a specification is tied to the context, not only the
     * first one the walk met: a reset must leave BOTH empty.
     */
    public function resetDropsEveryCaptorOfOneSpecification(): void
    {
        $paths = Arg::captor();
        $options = Arg::captor(DeliveryOptions::class);
        when(fn(): ?string => $this->store->temporaryUrl($paths->capture(), Arg::any(), $options->capture()))
            ->returns('url');

        $this->store->temporaryUrl('report.pdf', new \DateTimeImmutable(), new DeliveryOptions('r.pdf'));

        Assert::same(count($paths->all()), 1);
        Assert::same(count($options->all()), 1);

        Understudy::reset();

        Assert::same($paths->all(), []);
        Assert::same($options->all(), []);
    }

    public function resetDropsCapturedValuesWithTheContext(): void
    {
        $options = Arg::captor(DeliveryOptions::class);
        when(fn(): ?string => $this->store->temporaryUrl(Arg::any(), Arg::any(), $options->capture()))
            ->returns('url');

        $this->store->temporaryUrl('a.pdf', new \DateTimeImmutable(), new DeliveryOptions('kept.pdf'));
        Assert::same(count($options->all()), 1);

        Understudy::reset();

        Assert::same($options->all(), []);
    }

    public function aClosingScopeDropsWhatItCaptured(): void
    {
        $options = Arg::captor(DeliveryOptions::class);

        Understudy::scope(function () use ($options): void {
            $store = Understudy::for(UrlStore::class);
            when(fn(): ?string => $store->temporaryUrl(Arg::any(), Arg::any(), $options->capture()))
                ->returns('url');

            $store->temporaryUrl('a.pdf', new \DateTimeImmutable(), new DeliveryOptions('scoped.pdf'));
            Assert::same($options->last()->downloadName, 'scoped.pdf');
        });

        Assert::same($options->all(), []);
    }

    // --- Misuse -------------------------------------------------------------

    public function aCaptureLeakedIntoARealCallIsRejected(): void
    {
        $options = Arg::captor(DeliveryOptions::class);

        Expect::exception(MatcherLeaked::class)
            ->withMessageContaining('capture(' . DeliveryOptions::class . ')');

        $this->store->temporaryUrl('a.pdf', new \DateTimeImmutable(), $options->capture());
    }

    public function aCaptureRendersItselfInFailureMessages(): void
    {
        $options = Arg::captor(DeliveryOptions::class);

        Expect::exception(\Throwable::class)
            ->withMessageContaining('capture(' . DeliveryOptions::class . ')');

        verify(
            fn(): ?string => $this->store->temporaryUrl(Arg::any(), Arg::any(), $options->capture()),
            times: 1,
        );
    }
}
