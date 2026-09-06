<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\ConflictingExpectation;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Matcher\AnyRest;
use Rasuvaeff\Understudy\Matcher\Unspelled;
use Rasuvaeff\Understudy\Matcher\UnspelledTail;
use Rasuvaeff\Understudy\Runtime\Absent;
use Rasuvaeff\Understudy\Runtime\InvocationSignal;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Tests\Fixture\Rest\WideStorage;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Assert\ExpectNoAssertions;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

/**
 * `Arg::rest()`: "the arguments before me matter, the rest of the arity does
 * not" — the only matcher that lets a specification stop before the method's
 * required parameters run out.
 */
#[Test]
#[Covers(Arg::class)]
#[Covers(Understudy::class)]
#[Covers(\Rasuvaeff\Understudy\Expectation\Expectation::class)]
#[Covers(AnyRest::class)]
#[Covers(Unspelled::class)]
#[Covers(UnspelledTail::class)]
#[Covers(Absent::class)]
#[Covers(InvocationSignal::class)]
#[Covers(InvalidCallSpecification::class)]
#[Covers(Runtime::class)]
final class RestArgumentsTest
{
    private WideStorage $storage;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->storage = Understudy::for(WideStorage::class);
    }

    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    /**
     * @return list<mixed> a full argument list for recordOutcome()
     */
    private function fullArguments(string $key = 'svc'): array
    {
        return [$key, 1, ['threshold' => 3], new \DateTimeImmutable('2026-01-01'), true, null, 'attempt-1'];
    }

    // --- Matching -----------------------------------------------------------

    public function aPrefixStubMatchesWhateverFollows(): void
    {
        when(fn(): ?string => $this->storage->recordOutcome('svc', Arg::rest()))
            ->throws(new \RuntimeException('storage unavailable'));

        Expect::exception(\RuntimeException::class)->withMessage('storage unavailable');

        $this->storage->recordOutcome(...$this->fullArguments());
    }

    public function aDifferentPrefixFallsThroughToTheModeDefault(): void
    {
        when(fn(): ?string => $this->storage->recordOutcome('svc', Arg::rest()))
            ->returns('stored');

        Assert::null($this->storage->recordOutcome(...$this->fullArguments('other')));
    }

    public function aPrefixMayHoldMatchersOfItsOwn(): void
    {
        when(fn(): ?string => $this->storage->recordOutcome(Arg::string(), Arg::int(min: 1), Arg::rest()))
            ->returns('stored');

        Assert::same($this->storage->recordOutcome(...$this->fullArguments()), 'stored');
    }

    public function aBareRestMatchesEveryCallOfTheMethod(): void
    {
        when(fn(): ?string => $this->storage->recordOutcome(Arg::rest()))->returns('any');

        Assert::same($this->storage->recordOutcome(...$this->fullArguments('whatever')), 'any');
    }

    /**
     * The layering rule is untouched: a later, narrower specification wins
     * where it matches, and the broad prefix stub stays reachable as the
     * fallback.
     */
    public function aLaterNarrowerSpecificationWinsOverTheBroadPrefix(): void
    {
        // One argument list for both the specification and the call: literal
        // objects are compared by identity, so a rebuilt DateTimeImmutable
        // would not be "the same argument".
        $exact = $this->fullArguments();

        when(fn(): ?string => $this->storage->recordOutcome('svc', Arg::rest()))->returns('broad');
        when(fn(): ?string => $this->storage->recordOutcome(...$exact))->returns('narrow');

        Assert::same($this->storage->recordOutcome(...$exact), 'narrow');
        Assert::same(
            $this->storage->recordOutcome('svc', 2, [], new \DateTimeImmutable(), false, 'x', 'attempt-9'),
            'broad',
        );
    }

    #[ExpectNoAssertions]
    public function verifyCountsPrefixMatchedCalls(): void
    {
        $this->storage->recordOutcome(...$this->fullArguments());
        $this->storage->recordOutcome('svc', 2, [], new \DateTimeImmutable(), false, 'x', 'attempt-2');
        $this->storage->recordOutcome(...$this->fullArguments('other'));

        verify(fn(): ?string => $this->storage->recordOutcome('svc', Arg::rest()), times: 2);
    }

    #[ExpectNoAssertions]
    public function restAfterAnOptionalParameterActsOnTheMaterializedDefault(): void
    {
        // `tag('alpha')` logs the declared default, so the tail behind the
        // spelled prefix exists either way.
        $this->storage->tag('alpha');
        $this->storage->tag('alpha', 5);
        $this->storage->tag('beta', 5);

        verify(fn() => $this->storage->tag('alpha', Arg::rest()), times: 2);
    }

    public function restOnAVariadicMethodMatchesLikeRemaining(): void
    {
        when(fn(): int => $this->storage->emit('ch', Arg::rest()))->returns(3);

        Assert::same($this->storage->emit('ch', 'a', 'b'), 3);
        Assert::same($this->storage->emit('ch'), 3);
    }

    public function restDescribesItselfInFailureMessages(): void
    {
        Expect::exception(VerificationFailed::class)
            ->withMessageContaining("recordOutcome('svc', rest())");

        verify(fn(): ?string => $this->storage->recordOutcome('svc', Arg::rest()), times: 1);
    }

    /**
     * A `when()` and an `expect()` naming the same prefix specification are
     * the same conflict any other identical pair is — `rest()` compares as a
     * specification like every matcher.
     */
    public function anIdenticalPrefixSpecificationIsRefusedAcrossVerbs(): void
    {
        when(fn(): ?string => $this->storage->recordOutcome('svc', Arg::rest()))->returns('stored');

        Expect::exception(ConflictingExpectation::class);

        Understudy::expect(fn(): ?string => $this->storage->recordOutcome('svc', Arg::rest()));
    }

    // --- Refusals: the omission must be said, not implied -------------------

    public function anIncompleteSpecificationWithoutRestIsRefused(): void
    {
        Expect::exception(InvalidCallSpecification::class)->withMessage(
            "The specification for `recordOutcome()` passed 1 of its 7 arguments, and the ones it "
            . "left out are not all optional.\n"
            . 'Spell every required argument, or say the rest does not matter by ending with Arg::rest().',
        );

        when(fn(): ?string => $this->storage->recordOutcome('svc'));
    }

    public function anEmptySpecificationForARequiredArityIsRefused(): void
    {
        Expect::exception(InvalidCallSpecification::class)
            ->withMessageContaining('passed 0 of its 7 arguments');

        when(fn(): ?string => $this->storage->recordOutcome());
    }

    public function aNamedArgumentSkippingAParameterIsRefused(): void
    {
        Expect::exception(InvalidCallSpecification::class)->withMessage(
            "The specification for `recordOutcome()` omitted argument #2 — which the contract declares "
            . "required — but specified argument #7 after it.\n"
            . 'A specification spells its required arguments in order — use Arg::any() for one that '
            . 'does not matter.',
        );

        when(fn(): ?string => $this->storage->recordOutcome(key: 'svc', attemptId: 'attempt-1'));
    }

    /**
     * The hole is detected wherever the later argument sits — including
     * IMMEDIATELY after the first omitted one, the closest position a walk
     * that starts one step too late would miss.
     */
    public function aHoleRightBeforeTheNextSpecifiedArgumentIsRefused(): void
    {
        Expect::exception(InvalidCallSpecification::class)->withMessage(
            "The specification for `recordOutcome()` omitted argument #2 — which the contract declares "
            . "required — but specified argument #3 after it.\n"
            . 'A specification spells its required arguments in order — use Arg::any() for one that '
            . 'does not matter.',
        );

        when(fn(): ?string => $this->storage->recordOutcome(key: 'svc', config: []));
    }

    public function remainingDoesNotStandForOmittedParameters(): void
    {
        Expect::exception(InvalidCallSpecification::class)->withMessage(
            "`remaining()` describes a variadic tail, not parameters left unspelled, and the "
            . "specification for `recordOutcome()` stopped before its parameters ran out.\n"
            . 'End with Arg::rest() to say the remaining parameters do not matter.',
        );

        when(fn(): ?string => $this->storage->recordOutcome('svc', Arg::remaining()));
    }

    public function noneDoesNotStandForOmittedParameters(): void
    {
        Expect::exception(InvalidCallSpecification::class)
            ->withMessageContaining('`none()` describes a variadic tail');

        when(fn(): ?string => $this->storage->recordOutcome('svc', Arg::none()));
    }

    public function aMisplacedRestIsRefusedByPosition(): void
    {
        Expect::exception(InvalidCallSpecification::class)
            ->withMessageContaining('`rest()` stands for the whole variadic tail')
            ->withMessageContaining('argument #1 of `tag()`');

        when(fn() => $this->storage->tag(Arg::rest(), 5));
    }

    public function restCannotBeACombinatorOperand(): void
    {
        Expect::exception(InvalidCallSpecification::class)
            ->withMessageContaining('`rest()` stands for the whole variadic tail');

        Arg::allOf(Arg::rest());
    }

    // --- Arity fidelity outside recording -----------------------------------

    /**
     * The sentinel default exists for the recording phase. A real call that
     * omits a required argument gets the `ArgumentCountError` PHP itself would
     * have raised — a double must not be more permissive about arity than the
     * real implementation.
     */
    public function aRealCallOmittingARequiredArgumentIsAnArgumentCountError(): void
    {
        Expect::exception(\ArgumentCountError::class)
            ->withMessage('Too few arguments to function recordOutcome(), argument #2 not passed');

        $this->storage->recordOutcome('svc');
    }

    public function aRealCallWithFullArityIsUntouched(): void
    {
        Assert::null($this->storage->recordOutcome(...$this->fullArguments()));

        verify(fn(): ?string => $this->storage->recordOutcome(Arg::rest()), times: 1);
    }

    // --- Optional parameters a specification did not spell -------------------

    /**
     * The contract says a caller may omit an optional parameter; a
     * specification that omits it therefore says nothing about it, and matches
     * whatever the code under test passed there.
     *
     * Materializing the declared default instead — which is what the double
     * used to do — made arity an implicit part of every specification, and the
     * report then said `never called` beside a call that differed only in a
     * position the author never wrote.
     */
    #[ExpectNoAssertions]
    public function anUnspelledOptionalParameterMatchesWhateverWasPassed(): void
    {
        $this->storage->tag('alpha', 5);

        verify(fn() => $this->storage->tag('alpha'), times: 1);
    }

    #[ExpectNoAssertions]
    public function anUnspelledOptionalParameterAlsoMatchesTheDefaultedCall(): void
    {
        $this->storage->tag('alpha');
        $this->storage->tag('alpha', 5);
        $this->storage->tag('beta', 5);

        verify(fn() => $this->storage->tag('alpha'), times: 2);
    }

    /**
     * The real call is the other half: an omitted argument is logged as the
     * value the contract gives it, so `tag('alpha')` and `tag('alpha', 1)`
     * stay the same call in the log.
     */
    public function arealCallMaterializesTheContractsDefault(): void
    {
        $this->storage->tag('alpha');

        Assert::same(
            Understudy::lastCall(fn() => $this->storage->tag(Arg::any(), Arg::any()))?->args,
            ['alpha', 1],
        );
    }

    /**
     * A named argument may skip an optional parameter, so a specification
     * written with named arguments may too.
     */
    #[ExpectNoAssertions]
    public function aNamedArgumentSkippingAnOptionalParameterSaysNothingAboutIt(): void
    {
        $this->storage->emit('ch', 'a');
        $this->storage->tag(name: 'alpha', weight: 9);

        verify(fn() => $this->storage->tag(name: 'alpha'), times: 1);
    }

    /**
     * `Arg::rest()` on a signature whose remaining parameters are all optional
     * is accepted: the docs call it "declared parameters left unspelled", and
     * the engine used to refuse it because the optional ones had already
     * become literals by the time it looked.
     */
    #[ExpectNoAssertions]
    public function restIsAcceptedWhereOnlyOptionalParametersFollow(): void
    {
        when(fn() => $this->storage->tag(Arg::any(), Arg::rest()));
        when(fn() => $this->storage->tag(Arg::rest()));
    }

    /**
     * The report distinguishes what the test specified from what it left to
     * the contract: `…` is not `any()`, which the test would have had to write.
     */
    public function anUnspelledParameterRendersAsAnEllipsis(): void
    {
        $this->storage->tag('alpha', 5);

        Expect::exception(VerificationFailed::class)->withMessageContaining("tag('beta', …)");

        verify(fn() => $this->storage->tag('beta'));
    }

    // --- A matcher that cannot act where it was put -------------------------

    /**
     * A literal array is compared by identity, so a matcher inside one matches
     * nothing and says nothing about it. `Arg::containing()` is the matcher
     * that describes part of an array, and the refusal names it.
     */
    public function aMatcherInsideAnArrayArgumentIsRefused(): void
    {
        Expect::exception(InvalidCallSpecification::class)->withMessage(
            "`any()` sits inside the array given as argument #3 of `recordOutcome()`, where it is "
            . "compared by identity and can never match.\n"
            . 'Describe the array with Arg::containing([...]), which reads matchers in its entries, '
            . 'or the whole argument with Arg::satisfies().',
        );

        when(fn(): ?string => $this->storage->recordOutcome('svc', 1, ['id' => Arg::any()], Arg::rest()));
    }

    public function aMatcherNestedDeeperInsideAnArrayArgumentIsRefusedToo(): void
    {
        Expect::exception(InvalidCallSpecification::class)
            ->withMessageContaining('sits inside the array given as argument #3');

        when(fn(): ?string => $this->storage->recordOutcome(
            'svc',
            1,
            ['user' => ['id' => Arg::int()]],
            Arg::rest(),
        ));
    }

    /**
     * A specification and a stub naming the same call still collide when the
     * omission is what they have in common — the unspelled tail is part of the
     * specification, not a wildcard that makes two of them different.
     */
    public function twoVerbsOmittingTheSameOptionalParameterStillCollide(): void
    {
        when(fn() => $this->storage->tag('alpha'));

        Expect::exception(ConflictingExpectation::class);

        Understudy::expect(fn() => $this->storage->tag('alpha'));
    }
}
