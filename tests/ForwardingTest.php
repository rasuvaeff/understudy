<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Codegen\DoubleFactory;
use Rasuvaeff\Understudy\Codegen\TargetUnifier;
use Rasuvaeff\Understudy\Exception\ForwardingTargetMismatch;
use Rasuvaeff\Understudy\Exception\OriginalCallUnavailable;
use Rasuvaeff\Understudy\Exception\OriginalReturnTypeViolation;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\Bookkeeper;
use Rasuvaeff\Understudy\Tests\Fixture\Fwd\Chainable;
use Rasuvaeff\Understudy\Tests\Fixture\Fwd\Filler;
use Rasuvaeff\Understudy\Tests\Fixture\Fwd\RealChain;
use Rasuvaeff\Understudy\Tests\Fixture\Fwd\RealFiller;
use Rasuvaeff\Understudy\Tests\Fixture\Fwd\SealedChain;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(Runtime::class)]
#[Covers(Understudy::class)]
#[Covers(Invocation::class)]
#[Covers(TargetUnifier::class)]
#[Covers(DoubleFactory::class)]
final class ForwardingTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    // --- Delegating ---------------------------------------------------------

    public function anUnmatchedCallReachesTheRealInstance(): void
    {
        $real = new RealChain();
        $double = Understudy::for(Chainable::class);
        Understudy::forwarding($double, $real);

        Assert::same($double->label(), 'real');
    }

    /**
     * Forwarding is what happens when nothing matched. A configured call is
     * still configured — otherwise turning the mode on would silently undo
     * every stub already in place.
     */
    public function aStubbedCallStillWins(): void
    {
        $real = new RealChain();
        $double = Understudy::for(Chainable::class);
        Understudy::forwarding($double, $real);

        when(static fn(): string => $double->label())->returns('stubbed');

        Assert::same($double->label(), 'stubbed');
    }

    public function forwardedCallsAndTheirOutcomesAreRecorded(): void
    {
        $real = new RealChain();
        $double = Understudy::for(Chainable::class);
        Understudy::forwarding($double, $real);

        $double->label();

        $calls = Understudy::calls(static fn(): string => $double->label());

        Assert::same(count($calls), 1);
        Assert::true($calls[0]->didReturn());
        Assert::same($calls[0]->returned(), 'real');
    }

    /**
     * Understudy proxies an object; it does not instrument one. `describe()`
     * calls `label()` on the real instance, and that call happens inside the
     * real object, where no dispatcher can see it.
     */
    public function onlyTheCallAtTheBoundaryIsRecorded(): void
    {
        $real = new RealChain();
        $double = Understudy::for(Chainable::class);
        Understudy::forwarding($double, $real);

        Assert::same($double->describe(), 'described by real');
        Assert::same(count(Understudy::calls(static fn(): string => $double->describe())), 1);
        Assert::same(count(Understudy::calls(static fn(): string => $double->label())), 0);
    }

    // --- Identity through a chain -------------------------------------------

    /**
     * A fluent method that returned the real instance has to come back as the
     * double, or the chain quietly stops being doubled halfway through.
     */
    public function aFluentMethodComesBackAsTheDouble(): void
    {
        $real = new RealChain();
        $double = Understudy::for(Chainable::class);
        Understudy::forwarding($double, $real);

        $returned = $double->withTag('first')->withTag('second');

        Assert::same($returned, $double);
        Assert::same($double->seen(), ['first', 'second']);
    }

    /**
     * Another instance of the real class is not a double, and the override
     * declares `: static`. Returning it would violate the double's own
     * signature, and wrapping it would invent a double nobody asked for.
     */
    public function aStaticMethodReturningAnotherInstanceIsRejected(): void
    {
        $real = new RealChain();
        $double = Understudy::for(Chainable::class);
        Understudy::forwarding($double, $real);

        Expect::exception(OriginalReturnTypeViolation::class)
            ->withMessageContaining('returned a different `' . RealChain::class . '`')
            ->withMessageContaining('when(fn () => $double->detach(...))->returns($double)');

        $double->detach();
    }

    /**
     * `&` promises the caller's variable will be written. Collecting the
     * argument as `[$slot]` would copy it, and a forwarded method could never
     * keep that promise.
     */
    public function aByReferenceArgumentReachesTheCallersVariable(): void
    {
        $double = Understudy::for(Filler::class);
        Understudy::forwarding($double, new RealFiller());

        $slot = 'before';
        $double->fill($slot, 'after');

        Assert::same($slot, 'after');
    }

    /**
     * A `: never` method on a forwarding double has a real implementation to
     * reach, and that implementation is where the throw lives. Complaining
     * that nothing was configured would be answering for an object that can
     * answer for itself.
     */
    public function aNeverMethodReachesTheRealImplementation(): void
    {
        $double = Understudy::for(Filler::class);
        Understudy::forwarding($double, new RealFiller());

        Expect::exception(\DomainException::class)->withMessage('the real implementation refused');

        $double->collapse();
    }

    /**
     * The identity rule is about `static`, and only about `static`. A method
     * whose return type is the interface may legally answer with another
     * instance, and refusing that would forbid a factory.
     */
    public function aMethodWithoutStaticMayReturnAnotherInstance(): void
    {
        $real = new RealChain();
        $double = Understudy::for(Chainable::class);
        Understudy::forwarding($double, $real);

        $spawned = $double->spawn();

        Assert::instanceOf($spawned, RealChain::class);
        Assert::true($spawned !== $double);
    }

    /**
     * A call from another Fiber is answered by the context that owns the
     * double, not by the one that happens to be running: the test that
     * configured it is the one that must be able to verify it.
     */
    public function aCallFromAnotherFiberIsRecordedByTheOwner(): void
    {
        $real = new RealChain();
        $double = Understudy::for(Chainable::class);
        Understudy::forwarding($double, $real);

        $answered = null;

        $fiber = new \Fiber(static function () use ($double, &$answered): void {
            $answered = $double->label();
        });
        $fiber->start();

        Assert::same($answered, 'real');
        Assert::same(count(Understudy::calls(static fn(): string => $double->label())), 1);
    }

    // --- callOriginal() -----------------------------------------------------

    /**
     * The escape hatch inside an answer: behave normally, except here.
     */
    public function callOriginalDelegatesOneCall(): void
    {
        $real = new RealChain();
        $double = Understudy::for(Chainable::class);
        Understudy::forwarding($double, $real);

        when(static fn(): string => $double->label())
            ->answers(static fn(Invocation $call): string => strtoupper((string) $call->callOriginal()));

        Assert::same($double->label(), 'REAL');
    }

    /**
     * `callOriginal()` works whether or not the double forwards by default: it
     * says "this one call goes through", and the target is all it needs.
     */
    public function callOriginalWorksOnADoubleThatIsNotForwarding(): void
    {
        $real = new RealChain();
        $double = Understudy::for($real);

        when(static fn(): string => $double->label())
            ->answers(static fn(Invocation $call): mixed => $call->callOriginal());

        Assert::same($double->label(), 'real');
    }

    public function callOriginalWithoutATargetIsRejected(): void
    {
        $double = Understudy::for(Chainable::class);

        when(static fn(): string => $double->label())
            ->answers(static fn(Invocation $call): mixed => $call->callOriginal());

        Expect::exception(OriginalCallUnavailable::class)
            ->withMessageContaining('has no real instance to delegate `label()` to');

        $double->label();
    }

    // --- for($instance) -----------------------------------------------------

    /**
     * Wrapping something is not the same as delegating to it. `for($real)`
     * remembers where the double came from; until `forwarding()` says so, the
     * double still answers with defaults.
     */
    public function forAnInstanceRemembersTheTargetWithoutTurningForwardingOn(): void
    {
        $real = new RealChain();
        $double = Understudy::for($real);

        Assert::instanceOf($double, RealChain::class);
        Assert::same($double->label(), '');

        Understudy::forwarding($double);

        Assert::same($double->label(), 'real');
    }

    public function forAFinalInstanceIsRejected(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `' . SealedChain::class . '`: a final class cannot be extended, '
            . 'and an instance of one is already the class it is. Pass an interface it implements to '
            . 'Understudy::for() and give the instance to Understudy::forwarding($double, $real).',
        );

        Understudy::for(new SealedChain());
    }

    // --- Rejections ---------------------------------------------------------

    public function anInstanceThatMissesAContractIsRejected(): void
    {
        $double = Understudy::for(Chainable::class, Bookkeeper::class);

        Expect::exception(ForwardingTargetMismatch::class)
            ->withMessageContaining('stands in for `' . Bookkeeper::class . '`')
            ->withMessageContaining('`' . RealChain::class . '` is not one');

        Understudy::forwarding($double, new RealChain());
    }

    /**
     * A double delegating to a double — itself included — sends every call back
     * into the dispatcher it came from, and an unmatched one keeps coming back
     * until the stack runs out.
     */
    public function anUnderstudyCannotBeAForwardingTarget(): void
    {
        $double = Understudy::for(Chainable::class);
        $other = Understudy::for(Chainable::class);

        Expect::exception(ForwardingTargetMismatch::class)
            ->withMessageContaining('cannot forward to an understudy')
            ->withMessageContaining('until the stack runs out');

        Understudy::forwarding($double, $other);
    }

    public function aDoubleCannotForwardToItself(): void
    {
        $double = Understudy::for(Chainable::class);

        Expect::exception(ForwardingTargetMismatch::class)->withMessageContaining('cannot forward to an understudy');

        Understudy::forwarding($double, $double);
    }

    public function turningForwardingOnWithoutATargetIsRejected(): void
    {
        $double = Understudy::for(Chainable::class);

        Expect::exception(OriginalCallUnavailable::class)
            ->withMessageContaining('was asked to forward, and has no real instance to forward to');

        Understudy::forwarding($double);
    }

    // --- Interaction with the other modes ------------------------------------

    /**
     * A double that forwards has an answer for everything, so strictness would
     * be a contradiction. The last mode set is the one in force, and the test
     * that sets it says which it meant.
     */
    public function theLastModeSetIsTheOneInForce(): void
    {
        $real = new RealChain();
        $double = Understudy::for($real);

        Understudy::forwarding($double);
        Understudy::strict($double);

        Expect::exception(\Rasuvaeff\Understudy\Exception\StrictModeViolation::class)
            ->withMessageContaining('is strict and received an unexpected call');

        $double->label();
    }

    public function anExpectationStillCountsWhileForwarding(): void
    {
        $real = new RealChain();
        $double = Understudy::for($real);
        Understudy::forwarding($double);

        \Rasuvaeff\Understudy\expect(static fn(): string => $double->label())->times(1);

        Assert::same($double->label(), 'real');

        Understudy::verifyAll();
    }
}
