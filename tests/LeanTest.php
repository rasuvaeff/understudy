<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\OutcomeUnavailable;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Tests\Fixture\Lean\LeanStore;
use Rasuvaeff\Understudy\Tests\Fixture\Lean\Payload;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

/**
 * `Understudy::lean()`: the call log keeps the invocation, not the value it
 * answered — for a double whose returned values own OS resources, and for hot
 * loops where per-call retention is the cost.
 */
#[Test]
#[Covers(Understudy::class)]
#[Covers(Runtime::class)]
#[Covers(DoubleState::class)]
#[Covers(Invocation::class)]
#[Covers(OutcomeUnavailable::class)]
final class LeanTest
{
    private LeanStore $store;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->store = Understudy::for(LeanStore::class);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    public function theCallIsRecordedAndVerifiableWithoutItsValue(): void
    {
        Understudy::lean($this->store);
        when(fn(): ?string => $this->store->temporaryUrl(Arg::any(), Arg::any(), Arg::any()))
            ->returns('https://example.test/report');

        $url = $this->store->temporaryUrl('a.pdf', new \DateTimeImmutable(), null);

        // The caller still gets the value; only the log lets go of it.
        Assert::same($url, 'https://example.test/report');

        verify(fn(): ?string => $this->store->temporaryUrl('a.pdf', Arg::any(), Arg::any()), times: 1);

        $call = Understudy::lastCall(fn(): ?string => $this->store->temporaryUrl(Arg::any(), Arg::any(), Arg::any()));
        \assert($call instanceof Invocation);

        Assert::same($call->args[0], 'a.pdf');
        Assert::true($call->didReturn());
        Assert::false($call->didThrow());
    }

    public function readingTheDiscardedValueRefusesByName(): void
    {
        Understudy::lean($this->store);

        $this->store->temporaryUrl('a.pdf', new \DateTimeImmutable(), null);

        $call = Understudy::lastCall(fn(): ?string => $this->store->temporaryUrl(Arg::any(), Arg::any(), Arg::any()));
        \assert($call instanceof Invocation);

        Expect::exception(OutcomeUnavailable::class)->withMessage(
            'Call to `temporaryUrl()` returned, but the value was not kept: the understudy is lean '
            . '(Understudy::lean()). Drop lean() to read outcomes, or observe the value inside answers().',
        );

        $call->returned();
    }

    /**
     * The companion reading, on a double that is NOT lean: the log is what
     * retains the returned object until reset(). This is the documented
     * default — the test pins it so the lean contrast stays honest.
     */
    public function withoutLeanTheLogRetainsTheReturnedObject(): void
    {
        when(fn(): ?Payload => $this->store->open(Arg::any()))
            ->answers(static fn(): Payload => new Payload('kept.pdf'));

        $returned = $this->store->open('a.pdf');
        \assert($returned instanceof Payload);
        $reference = \WeakReference::create($returned);
        unset($returned);
        gc_collect_cycles();

        Assert::instanceOf($reference->get(), Payload::class);

        Understudy::reset();
        gc_collect_cycles();

        Assert::null($reference->get());
    }

    /**
     * The point of the feature: once the call ends, nothing references the
     * returned value, so a value owning an OS resource is collectable during
     * the test's own teardown rather than after it.
     */
    public function aLeanDoubleReleasesTheReturnedObjectAtTheCall(): void
    {
        Understudy::lean($this->store);
        when(fn(): ?Payload => $this->store->open(Arg::any()))
            ->answers(static fn(): Payload => new Payload('gone.pdf'));

        $returned = $this->store->open('a.pdf');
        \assert($returned instanceof Payload);
        $reference = \WeakReference::create($returned);
        unset($returned);
        gc_collect_cycles();

        Assert::null($reference->get());
    }

    public function aThrownOutcomeIsStillRecorded(): void
    {
        Understudy::lean($this->store);
        when(fn() => $this->store->note('boom'))->throws(new \DomainException('kept'));

        try {
            $this->store->note('boom');
        } catch (\DomainException) {
            // Expected; the log's reading is what the test is about.
        }

        $call = Understudy::lastCall(fn() => $this->store->note('boom'));
        \assert($call instanceof Invocation);

        Assert::true($call->didThrow());
        Assert::instanceOf($call->thrown(), \DomainException::class);
    }

    public function leanIsPerDouble(): void
    {
        $other = Understudy::for(LeanStore::class);
        Understudy::lean($this->store);

        when(fn() => $other->note(Arg::any()))->returns(null);
        $other->note('payload');

        $call = Understudy::lastCall(fn() => $other->note(Arg::any()));
        \assert($call instanceof Invocation);

        Assert::null($call->returned());
    }

    public function theTranscriptSaysTheValueWasNotKept(): void
    {
        Understudy::lean($this->store);
        when(fn(): ?string => $this->store->temporaryUrl(Arg::any(), Arg::any(), Arg::any()))->returns('url');

        $this->store->temporaryUrl('a.pdf', new \DateTimeImmutable(), null);

        Assert::string(Understudy::transcript($this->store))
            ->contains('returned (value not kept: lean)');
    }
}
