<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Codegen\DoubleFactory;
use Rasuvaeff\Understudy\Exception\ForwardingTargetMismatch;
use Rasuvaeff\Understudy\Exception\OriginalReturnTypeViolation;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Tests\Fixture\Fwd\Chainable;
use Rasuvaeff\Understudy\Tests\Fixture\Fwd\OtherPipeline;
use Rasuvaeff\Understudy\Tests\Fixture\Fwd\Pipeline;
use Rasuvaeff\Understudy\Tests\Fixture\Fwd\RealChain;
use Rasuvaeff\Understudy\Tests\Fixture\Fwd\SpecialPipeline;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\expectSequence;
use function Rasuvaeff\Understudy\when;

/**
 * Inline forwarding: a class double runs the real bodies on itself, entered
 * through a reflection on the doubled class — `$this` stays the double, so a
 * self-call inside real code re-enters dispatch and meets stubs and the call
 * log.
 */
#[Test]
#[Covers(Runtime::class)]
#[Covers(Understudy::class)]
#[Covers(Invocation::class)]
#[Covers(DoubleState::class)]
#[Covers(DoubleFactory::class)]
#[Covers(OriginalReturnTypeViolation::class)]
#[Covers(ForwardingTargetMismatch::class)]
final class InlineForwardingTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    public function aStubWinsOverTheRealBody(): void
    {
        $double = Understudy::delegate(Pipeline::class, new Pipeline('prod'));

        when(fn() => $double->apply(Arg::any()))->returns('  stubbed  ');

        // run() is unmatched, runs the real template body — which calls
        // $this->apply(), and that self-call re-enters the double and lands
        // on the stub rather than in the real body.
        Assert::same($double->run('x'), 'stubbed');
    }

    public function selfCallsAreRecordedLikeAnyOtherCall(): void
    {
        $double = Understudy::delegate(Pipeline::class, new Pipeline('prod', ['a']));

        // The real bodies, all of them: apply() appends the steps, normalize()
        // trims what it was handed.
        Assert::same($double->run('  x  '), 'x  +a');

        // The boundary call and the self-call are both in the log — a verify()
        // or nothingElse() sees them, which is what a partial-mock port
        // expects of makePartial().
        Assert::same(count(Understudy::calls(fn() => $double->run(Arg::any()))), 1);
        Assert::same(count(Understudy::calls(fn() => $double->apply(Arg::any()))), 1);

        $run = Understudy::calls(fn() => $double->run(Arg::any()));
        Assert::same($run[0]->args, ['  x  ']);

        // The self-call was recorded with what the real body passed it, not
        // with what the boundary received.
        $apply = Understudy::calls(fn() => $double->apply(Arg::any()));
        Assert::same($apply[0]->args, ['  x  ']);
        Assert::same($apply[0]->returned(), '  x  +a');
    }

    /**
     * The behaviour change this brings: a self-call is a call, so it counts
     * toward an expectation the test made about that method.
     */
    public function aSelfCallCountsTowardAnExpectation(): void
    {
        $double = Understudy::delegate(Pipeline::class, new Pipeline('prod'));

        expect(fn() => $double->apply(Arg::any()))->times(1);

        // apply() is never called from the test — only from inside run().
        $double->run('x');

        Assert::same(Understudy::verifyAll(), 1);
    }

    /**
     * An armed protocol judges every call the dispatcher sees, so a self-call
     * now fills a step the test never made from the outside. The step is
     * satisfied by the real code's own call, which is what a protocol over a
     * template method means once the bodies run inline.
     */
    public function aSelfCallFillsAStepOfAnArmedProtocol(): void
    {
        $double = Understudy::delegate(Pipeline::class, new Pipeline('prod'));

        expectSequence(
            fn() => $double->run('x'),
            fn() => $double->apply('x'),
        );

        // Only the boundary call is made here; apply() arrives from inside
        // the real template body and completes the protocol.
        $double->run('x');

        Assert::same(Understudy::verifyAll(), 1);
    }

    public function theRealStateIsCopiedOntoTheDouble(): void
    {
        // The constructor never ran on the double; the bodies it now runs
        // inline read `$this->` state, and what they see has to be the real
        // instance's — promoted readonly included, which is the pre-8.4
        // initialization-scope trap.
        $double = Understudy::delegate(Pipeline::class, new Pipeline('prod', ['a', 'b']));

        Assert::same($double->describe(), 'prod:2');
        Assert::same($double->apply('x'), 'x+a,b');
    }

    /**
     * The scope trap itself: `tag` is declared `protected readonly` on the
     * parent class, and before PHP 8.4 it can only be initialized from
     * there — a reflection reached through the double's own class writes from
     * the wrong scope.
     */
    public function anInheritedReadonlyPropertyIsCopied(): void
    {
        $double = Understudy::delegate(Pipeline::class, new Pipeline('prod', [], 'inherited'));

        Assert::same($double->tag(), 'inherited');
    }

    /**
     * Two classes declaring the same method name, doubled inline in one
     * process: each double has to run its own class's body. The dispatcher
     * caches the bodies it enters, and a cache keyed by the method name alone
     * would answer the second double with the first one's implementation.
     */
    public function twoClassesSharingAMethodNameKeepTheirOwnBodies(): void
    {
        $pipeline = Understudy::delegate(Pipeline::class, new Pipeline('prod', ['a']));
        $other = Understudy::delegate(OtherPipeline::class, new OtherPipeline());

        Assert::same($pipeline->apply('x'), 'x+a');
        Assert::same($other->apply('x'), 'other(x)');

        // And in the other order, so the test does not depend on which body
        // was cached first.
        Assert::same($other->apply('y'), 'other(y)');
        Assert::same($pipeline->apply('y'), 'y+a');
    }

    public function whatTheDonorLeftUninitializedStaysUninitialized(): void
    {
        $real = new Pipeline('prod');
        $real->memo = 'remembered';
        unset($real->memo);

        $double = Understudy::delegate(Pipeline::class, $real);

        // Not a default-filled null: the donor's truth is "never
        // initialized", and PHP's own error is the diagnosis — the same one
        // the real object would have raised.
        Expect::exception(\Error::class)->withMessageContaining('must not be accessed before initialization');

        $double->recall();
    }

    /**
     * A hooked property carries its state in a backing store, and a copy must
     * reach it without running the hooks: reading through them would execute
     * the donor's code during what is test setup, and writing through them
     * would run a `set` the real object already ran. A virtual property has no
     * state at all, so there is nothing to copy — only its hooks, which the
     * generated class inherits.
     *
     * Built by eval: the syntax is a parse error on 8.3, where a file carrying
     * it would take the whole suite down rather than skip a test.
     */
    public function aHookedPropertyIsCopiedThroughItsBackingStore(): void
    {
        if (PHP_VERSION_ID < 80400) {
            // Nothing to skip: the language cannot express a hook yet.
            Assert::false(method_exists(\ReflectionProperty::class, 'hasHooks'));

            return;
        }

        $class = 'HookedDonor_' . PHP_VERSION_ID;

        eval(sprintf(
            'namespace %s; class %s { public int $scaled { get => $this->scaled * 2; '
            . 'set { $this->scaled = $value; } } public string $virtual { get => \'computed\'; } '
            . 'public string $plain; '
            . 'public function __construct() { $this->scaled = 5; $this->plain = \'carried\'; } '
            . 'public function read(): int { return $this->scaled; } '
            . 'public function readPlain(): string { return $this->plain; } '
            . 'public function readVirtual(): string { return $this->virtual; } }',
            __NAMESPACE__,
            $class,
        ));

        /** @var class-string $fqcn */
        $fqcn = __NAMESPACE__ . '\\' . $class;
        $double = Understudy::delegate($fqcn, new $fqcn());

        // The backing store holds 5, and the inherited `get` hook doubles it:
        // the state arrived, and the hooks were not run to put it there.
        Assert::same($double->read(), 10);
        Assert::same($double->readVirtual(), 'computed');

        // And the copy carried on past the hooked properties to the plain one
        // declared after them.
        Assert::same($double->readPlain(), 'carried');
    }

    public function aFluentSelfReturnIsTheDoubleItself(): void
    {
        $double = Understudy::delegate(Pipeline::class, new Pipeline('prod'));

        // The parent body's `return $this;` already *is* the double — no
        // adoption needed, the chain stays doubled by itself.
        Assert::same($double->addStep('x'), $double);
    }

    public function aByReferenceArgumentIsWrittenThrough(): void
    {
        $double = Understudy::delegate(Pipeline::class, new Pipeline('prod'));

        $slot = 'empty';
        $double->fill($slot, 'v');

        // The real body wrote through the caller's variable, using state
        // (the promoted name) copied from the donor.
        Assert::same($slot, 'v:prod');
    }

    public function aNeverMethodThrowsFromTheRealBody(): void
    {
        $double = Understudy::delegate(Pipeline::class, new Pipeline('prod'));

        Expect::exception(\DomainException::class)->withMessage('refused');

        $double->fail('refused');
    }

    /**
     * `static` inside a body running inline is the generated class: the body
     * runs on the double, and the double is a subclass of the real one.
     */
    public function staticInsideARealBodyIsTheGeneratedClass(): void
    {
        $double = Understudy::delegate(Pipeline::class, new Pipeline('prod'));

        Assert::same($double->className(), $double::class);
        Assert::true($double instanceof Pipeline);
        Assert::false($double->className() === Pipeline::class);
    }

    /**
     * `new static` satisfies the override's `: static` — the object is of the
     * generated class — so the language no longer refuses it. No context
     * knows that object, every call on it would fail as an unregistered
     * double, and the refusal the proxy path used to raise now comes from
     * here.
     */
    public function aNewStaticFromARealBodyIsRefused(): void
    {
        $double = Understudy::delegate(Pipeline::class, new Pipeline('prod'));

        Expect::exception(OriginalReturnTypeViolation::class)
            ->withMessageContaining('built a new instance of itself')
            ->withMessageContaining('unregistered double')
            ->withMessageContaining('->returns($double)');

        $double->duplicate();
    }

    /**
     * The refusal is about an object no context knows, not about the object
     * being another instance: a clone was adopted while the body ran, so it
     * comes back — and it comes back *working*. PHP copied the state the real bodies read, and the
     * clone inherits the original's forwarding, so it answers for real where
     * the proxy form would have handed over a real clone. What it does not
     * inherit is this double's expectations: a stub written here does not
     * follow the copy, exactly as for a clone the test makes itself.
     */
    public function aCloneFromANonStaticMethodComesBackForwarding(): void
    {
        $real = new Pipeline('prod', ['a']);
        $double = Understudy::delegate(Pipeline::class, $real);

        $mirror = $double->mirror();

        Assert::instanceOf($mirror, Pipeline::class);
        Assert::false($mirror === $double);
        Assert::same($mirror->describe(), 'prod:1');

        // Its own object from there on: a step added to the copy is not the
        // original's, and neither is the donor's.
        $mirror->addStep('b');
        Assert::same($mirror->describe(), 'prod:2');
        Assert::same($double->describe(), 'prod:1');
        Assert::same($real->describe(), 'prod:1');

        // A complete forwarding double, instance and all: turning the mode on
        // again finds the instance it came from instead of refusing for want
        // of one.
        Understudy::forwarding($mirror);
        Assert::same($mirror->describe(), 'prod:2');
    }

    /**
     * The case nothing returns: a body that clones itself and computes over
     * the copy. Under the proxy that copy was a real object; inline it is a
     * double, and it has to go on running real code or the body silently
     * computes over defaults.
     */
    public function aCloneMadeInsideARealBodyKeepsWorking(): void
    {
        $double = Understudy::delegate(Pipeline::class, new Pipeline('prod', ['a']));

        Assert::same($double->preview('b'), 'prod:2');

        // The original is untouched by what the body did to its copy.
        Assert::same($double->describe(), 'prod:1');
    }

    /**
     * A clone the TEST makes is not inside any body, so nothing propagates:
     * it is a fresh double of the right class, which is the documented rule
     * for `clone $double`. Real bodies ran before the clone — one of them
     * threw — and neither left the double looking like it was still running.
     */
    public function aCloneMadeByTheTestIsAFreshDouble(): void
    {
        $double = Understudy::delegate(Pipeline::class, new Pipeline('prod', ['a']));

        $double->describe();

        try {
            $double->fail('thrown from the real body');
        } catch (\DomainException) {
            // The throw is the point: an unwound body must not stay on the
            // stack of bodies that are running.
        }

        $copy = clone $double;

        Assert::same($copy->describe(), '');
    }

    /**
     * A static property is not state the double can hold a copy of — it
     * belongs to the class, and both the donor and the double read the same
     * one. The copy skips it and carries on to the properties declared after
     * it.
     */
    public function aStaticPropertyIsLeftToTheClass(): void
    {
        $before = Pipeline::$built;
        $double = Understudy::delegate(Pipeline::class, new Pipeline('prod', ['a']));

        // One construction, the donor's: the double's constructor never ran.
        Assert::same(Pipeline::$built, $before + 1);

        // And the instance properties declared after the static one arrived.
        Assert::same($double->describe(), 'prod:1');
    }

    /**
     * A `clone`-based wither declared `: static` is the other side of that:
     * the copy was adopted while the body ran, with this double's forwarding,
     * so the chain comes back working rather than refused.
     */
    public function aClonedStaticWitherComesBackForwarding(): void
    {
        $double = Understudy::delegate(Pipeline::class, new Pipeline('prod', ['a']));

        $copy = $double->copy();

        Assert::false($copy === $double);
        Assert::same($copy->recall(), 'copied');
        Assert::same($copy->describe(), 'prod:1');
    }

    /**
     * `callOriginal()` on an inline double runs the parent body on the double,
     * not on the donor: the donor's copy of the state stopped being the
     * current one at the first inline call.
     */
    public function callOriginalRunsInlineOnAnInlineDouble(): void
    {
        $real = new Pipeline('prod', ['a']);
        $double = Understudy::delegate(Pipeline::class, $real);

        when(fn() => $double->addStep(Arg::any()))
            ->answers(fn(Invocation $call) => $call->callOriginal());

        // Fluent through the escape hatch: the parent body returned `$this`,
        // which is the double.
        Assert::same($double->addStep('b'), $double);

        // The double grew a step; the donor did not.
        Assert::same($double->describe(), 'prod:2');
        Assert::same($real->describe(), 'prod:1');
    }

    /**
     * An interface double has no bodies to reach, so `callOriginal()` — and
     * forwarding itself — stays the proxy form, where a self-call happens
     * inside the real object and nothing sees it.
     */
    public function callOriginalStaysTheProxyFormOnAnInterfaceDouble(): void
    {
        $double = Understudy::delegate(Chainable::class, new RealChain());

        when(fn() => $double->describe())
            ->answers(fn(Invocation $call) => $call->callOriginal());

        Assert::same($double->describe(), 'described by real');
        Assert::same(count(Understudy::calls(fn() => $double->label())), 0);
    }

    /**
     * A donor that is a subclass carries overrides the doubled class's bodies
     * do not contain. Running those bodies instead would change which code
     * executes, so such a double keeps the proxy form: the override answers,
     * and only the boundary call is recorded.
     */
    public function aSubclassDonorKeepsTheProxyForm(): void
    {
        $double = Understudy::delegate(Pipeline::class, new SpecialPipeline('prod'));

        // SpecialPipeline::apply(), reached through the real object's own
        // self-call — not Pipeline::apply() run on the double.
        Assert::same($double->run('  x  '), 'X  !');
        Assert::same(count(Understudy::calls(fn() => $double->apply(Arg::any()))), 0);
    }

    public function aSecondDonorIsRefusedOnAnInlineDouble(): void
    {
        $double = Understudy::delegate(Pipeline::class, new Pipeline('first'));

        Expect::exception(ForwardingTargetMismatch::class)
            ->withMessageContaining('already runs the bodies of')
            ->withMessageContaining('cannot be re-pointed')
            ->withMessageContaining('Build a second double for the other instance');

        Understudy::forwarding($double, new Pipeline('second'));
    }

    /**
     * The same instance twice is the test saying the same thing twice: the
     * state copy already happened, and repeating it would be the write a
     * `readonly` property refuses.
     */
    public function pointingAnInlineDoubleAtTheSameDonorAgainIsIdempotent(): void
    {
        $real = new Pipeline('prod', ['a']);
        $double = Understudy::delegate(Pipeline::class, $real);

        $double->addStep('b');
        Understudy::forwarding($double, $real);

        Assert::same($double->describe(), 'prod:2');
    }
}
