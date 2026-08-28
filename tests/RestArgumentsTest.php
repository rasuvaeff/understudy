<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\ConflictingExpectation;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Matcher\AnyRest;
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
#[Covers(AnyRest::class)]
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
            "The specification for `recordOutcome()` passed 1 of its 7 arguments.\n"
            . 'Spell every argument, or say the rest does not matter by ending with Arg::rest().',
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
            "The specification for `recordOutcome()` omitted argument #2 but specified argument #7 after it.\n"
            . 'A specification spells its arguments in order — use Arg::any() for one that does not matter.',
        );

        when(fn(): ?string => $this->storage->recordOutcome(key: 'svc', attemptId: 'attempt-1'));
    }

    public function remainingDoesNotStandForOmittedParameters(): void
    {
        Expect::exception(InvalidCallSpecification::class)->withMessage(
            "`remaining()` describes a variadic tail, not parameters left unspelled, and the "
            . "specification for `recordOutcome()` stopped before its required parameters ran out.\n"
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
}
