<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Codegen\Blueprint;
use Rasuvaeff\Understudy\Codegen\DoubleFactory;
use Rasuvaeff\Understudy\Codegen\PropertyDefaults;
use Rasuvaeff\Understudy\Codegen\TargetUnifier;
use Rasuvaeff\Understudy\Codegen\TypeRenderer;
use Rasuvaeff\Understudy\Exception\ContextOwnershipViolation;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\AbstractLedger;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\Bookkeeper;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\Countable;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\CountingStamp;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\DefaultsContract;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\FinalLedger;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\Ledger;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\NestedObjectDefaultContract;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\ObjectDefaultContract;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\PrivateConstantContract;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\PropertyLedger;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\ReadonlyLedger;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\SealedLedger;
use Rasuvaeff\Understudy\Tests\Fixture\Cls\Stamp;
use Rasuvaeff\Understudy\Tests\Fixture\Suit;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\AbstractStaticFromInterface;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingContract;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingWiderParameter;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(DoubleFactory::class)]
#[Covers(Understudy::class)]
#[Covers(PropertyDefaults::class)]
#[Covers(TargetUnifier::class)]
#[Covers(Blueprint::class)]
#[Covers(TypeRenderer::class)]
final class ClassDoubleTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    // --- Standing in for a class --------------------------------------------

    /**
     * The target's constructor throws, so a double that ran it could not be
     * built at all — and its `named` property would say so.
     */
    public function theTargetConstructorIsNeverRun(): void
    {
        $ledger = Understudy::for(Ledger::class);

        Assert::instanceOf($ledger, Ledger::class);
        Assert::same($ledger->named, 'declared');
    }

    public function aStubbedMethodAnswersInsteadOfTheRealOne(): void
    {
        $ledger = Understudy::for(Ledger::class);

        when(static fn(): string => $ledger->describe())->returns('the double');

        Assert::same($ledger->describe(), 'the double');
    }

    /**
     * The real `record()` returns -1. A double that fell through to the parent
     * would say -1; the loose default for `int` is 0.
     */
    public function anUnstubbedMethodAnswersWithTheLooseDefaultNotTheRealBody(): void
    {
        $ledger = Understudy::for(Ledger::class);

        Assert::same($ledger->record('rent'), 0);
    }

    /**
     * Protected methods are overridden and dispatched, which is what puts them
     * in the transcript and under strict mode. They stay unconfigurable from a
     * setup closure only because PHP's own visibility says so.
     */
    public function protectedMethodsAreOverriddenAndDispatched(): void
    {
        $ledger = Understudy::for(Ledger::class);

        $audit = new \ReflectionMethod($ledger, 'audit');

        Assert::same($audit->getDeclaringClass()->getName(), $ledger::class);
        Assert::same($audit->invoke($ledger, 'entry'), '');
    }

    public function privateMethodsAreNotTouched(): void
    {
        $ledger = Understudy::for(Ledger::class);

        $secret = new \ReflectionMethod($ledger, 'secret');

        Assert::same($secret->getDeclaringClass()->getName(), Ledger::class);
    }

    /**
     * A static method has no instance state to intercept, and overriding it
     * would change what the target does for everybody. The parent's stays.
     */
    public function staticMethodsKeepTheTargetImplementation(): void
    {
        $ledger = Understudy::for(Ledger::class);
        $version = new \ReflectionMethod($ledger, 'version');

        Assert::same($version->getDeclaringClass()->getName(), Ledger::class);
        Assert::same($ledger::version(), '1.0');
    }

    /**
     * The target's destructor throws. Nothing is caught here on purpose: an
     * inherited destructor would end the test with an uncaught exception.
     */
    public function theTargetDestructorNeverRuns(): void
    {
        $ledger = Understudy::for(Ledger::class);
        $generated = $ledger::class;

        unset($ledger);
        gc_collect_cycles();

        Assert::same((new \ReflectionMethod($generated, '__destruct'))->getDeclaringClass()->getName(), $generated);
    }

    public function anAbstractTargetIsDoubledWithItsAbstractMethodsImplemented(): void
    {
        $ledger = Understudy::for(AbstractLedger::class);

        when(static fn(): int => $ledger->total())->returns(21);

        Assert::same($ledger->total(), 21);
        // `twice()` is concrete on the target and overridden like any other
        // method, so it answers with the loose default rather than doubling.
        Assert::same($ledger->twice(), 0);
    }

    public function aClassAndInterfacesAreDoubledTogether(): void
    {
        $ledger = Understudy::for(Ledger::class, Bookkeeper::class);

        Assert::instanceOf($ledger, Ledger::class);
        Assert::instanceOf($ledger, Bookkeeper::class);

        when(static fn(): int => $ledger->balance())->returns(5);

        Assert::same($ledger->balance(), 5);
    }

    public function aClassImplementationKeepsAStaticInterfaceMethodOutOfTheDouble(): void
    {
        eval('interface StaticContractForReview { public static function ping(): int; }');
        eval('class StaticTargetForReview { public static function ping(): int { return 7; } }');

        $double = Understudy::for(\StaticTargetForReview::class, \StaticContractForReview::class);

        Assert::same($double::ping(), 7);
    }

    /**
     * Before this was checked, `for()` on such a class reached `eval()` and
     * PHP answered with a fatal error naming the unimplemented method — not an
     * exception, so nothing downstream could report it.
     */
    public function anAbstractTargetWithAnUnimplementedInterfaceStaticIsBuilt(): void
    {
        $double = Understudy::for(AbstractStaticFromInterface::class);

        Assert::instanceOf($double, AbstractStaticFromInterface::class);
        Assert::instanceOf($double, StaticPingContract::class);

        Expect::exception(InvalidCallSpecification::class)->withMessage(
            "Static method `ping()` cannot be called on an understudy because static calls have no instance state.\n"
            . 'Inject an instance dependency and double that contract instead.',
        );

        $double::ping('abc');
    }

    /**
     * The compatible case end to end: the class static survives into the
     * generated subclass and answers there, which is what dropping the
     * interface's declaration from the dispatchers is claiming.
     */
    public function aWiderClassStaticStillAnswersThroughTheDouble(): void
    {
        $double = Understudy::for(StaticPingWiderParameter::class, StaticPingContract::class);

        Assert::same($double::ping('abc'), 3);
        Assert::same($double::ping(7), 7);
        Assert::instanceOf($double, StaticPingContract::class);
    }

    public function aStaticMultiTargetReturnConflictIsReportedBeforeEval(): void
    {
        eval('interface StaticConflictContractForReview { public static function ping(): string; }');
        eval('class StaticConflictTargetForReview { public static function ping(): int { return 7; } }');

        Expect::exception(UnsupportedTarget::class)
            ->withMessageContaining('method `ping()`');

        Understudy::for(\StaticConflictTargetForReview::class, \StaticConflictContractForReview::class);
    }

    public function aStaticMultiTargetParameterConflictIsReportedBeforeEval(): void
    {
        eval('interface StaticParameterContractForReview { public static function ping(string $value): int; }');
        eval('class StaticParameterTargetForReview { public static function ping(int $value): int { return $value; } }');

        Expect::exception(UnsupportedTarget::class)
            ->withMessageContaining('incompatible static signature');

        Understudy::for(\StaticParameterTargetForReview::class, \StaticParameterContractForReview::class);
    }

    /**
     * Five interfaces the language forbids a userland class to implement.
     * They used to walk past every refusal in the factory and be answered by
     * the compiler instead, as a fatal out of `eval()` — uncatchable, and
     * fatal to the whole run rather than to one test. `DateTimeInterface` and
     * `Throwable` are the first two contracts anybody reaches for.
     *
     * The whole message is asserted, not a fragment: it is the only thing the
     * reader gets, and every half of a concatenation in one is a mutant a
     * `contains()` cannot see.
     *
     * @param class-string $contract the interface `for()` must refuse
     * @param string       $reason   the message after the name of the target
     */
    #[DataProvider('undoublableInterfaceProvider')]
    public function aBuiltInInterfaceNoClassMayImplementIsRefused(string $contract, string $reason): void
    {
        Expect::exception(UnsupportedTarget::class)
            ->withMessage(sprintf('Cannot create an understudy for `%s`: %s', $contract, $reason));

        Understudy::for($contract);
    }

    public static function undoublableInterfaceProvider(): iterable
    {
        yield 'throwable' => [
            \Throwable::class,
            'PHP forbids a userland class to implement Throwable directly. Double a concrete exception '
            . 'class instead, or an interface of your own that extends none of it.',
        ];
        yield 'unit enum' => [
            \UnitEnum::class,
            'only an enum may implement it, and an enum cannot be doubled at all — its cases are the '
            . 'values themselves. Pass the case you need, or double an interface the enum implements.',
        ];
        yield 'backed enum' => [
            \BackedEnum::class,
            'only an enum may implement it, and an enum cannot be doubled at all — its cases are the '
            . 'values themselves. Pass the case you need, or double an interface the enum implements.',
        ];
        yield 'date time' => [
            \DateTimeInterface::class,
            'PHP forbids a userland class to implement DateTimeInterface. Pass a real \DateTimeImmutable, '
            . 'or put a clock interface of your own in front of it and double that.',
        ];
        yield 'traversable' => [
            \Traversable::class,
            'PHP requires it to be reached through Iterator or IteratorAggregate. Double one of those — '
            . 'both work here — or an interface of yours that extends one.',
        ];
    }

    /**
     * A name written with a leading backslash is the same interface, and
     * `Understudy::for('\\Throwable')` is how it reads when the list is
     * assembled from strings rather than from `::class`.
     */
    public function aLeadingBackslashDoesNotHideTheRefusal(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessageContaining('Double a concrete exception class');

        Understudy::for('\\Throwable');
    }

    /**
     * The neighbours: being built in is not the reason those five are
     * refused, and a rule written that way would take these with it.
     *
     * @param class-string $contract
     */
    #[DataProvider('doublableBuiltInProvider')]
    public function aBuiltInInterfaceThatMayBeImplementedStillDoubles(string $contract): void
    {
        Assert::instanceOf(Understudy::for($contract), $contract);
    }

    public static function doublableBuiltInProvider(): iterable
    {
        yield 'iterator' => [\Iterator::class];
        yield 'iterator aggregate' => [\IteratorAggregate::class];
        yield 'stringable' => [\Stringable::class];
        yield 'countable' => [\Countable::class];
    }

    /**
     * A contract list assembled programmatically can name the same interface
     * twice, and `implements A, A` does not compile — another fatal out of
     * `eval()`. The duplicate adds nothing the first mention did not.
     */
    public function aDuplicatedContractIsAcceptedRatherThanFatal(): void
    {
        $double = Understudy::for(BookRepository::class, BookRepository::class);

        Assert::instanceOf($double, BookRepository::class);
    }

    /**
     * PHP allows a readonly class to be extended only by another readonly one,
     * and a readonly class has no writable properties — so the initialization
     * step must find nothing to do. A "fix" that made it write would raise a
     * runtime Error instead.
     */
    public function aReadonlyTargetProducesAReadonlyDoubleAndNoPropertyWrites(): void
    {
        $ledger = Understudy::for(ReadonlyLedger::class);

        Assert::true((new \ReflectionClass($ledger))->isReadOnly());
        Assert::same(PropertyDefaults::forTarget(new \ReflectionClass(ReadonlyLedger::class)), []);

        when(static fn(): string => $ledger->describe())->returns('doubled');

        Assert::same($ledger->describe(), 'doubled');
    }

    // --- Public properties --------------------------------------------------

    public function writablePublicPropertiesStartAtAnEmptyValue(): void
    {
        $ledger = Understudy::for(PropertyLedger::class);

        Assert::same($ledger->count, 0);
        Assert::same($ledger->rate, 0.0);
        Assert::false($ledger->open);
        Assert::same($ledger->note, '');
        Assert::same($ledger->rows, []);
        Assert::same($ledger->stream, []);
        Assert::null($ledger->parent);
        Assert::null($ledger->anything);
        // A declared default is PHP's own business, and an untyped property is
        // null before anything here runs.
        Assert::same($ledger->declared, 'kept');
        Assert::null($ledger->untyped);
    }

    /**
     * The initializer decides per property, and each decision is a branch that
     * has to be visible: what it writes, what it leaves to PHP, and what it
     * must not touch at all.
     */
    public function theInitializerTouchesExactlyTheWritableUntypedefaultedProperties(): void
    {
        $defaults = PropertyDefaults::forTarget(new \ReflectionClass(PropertyLedger::class));

        Assert::same($defaults, [
            'count' => 0,
            'rate' => 0.0,
            'open' => false,
            'note' => '',
            'rows' => [],
            'stream' => [],
            'parent' => null,
            'anything' => null,
        ]);
    }

    /**
     * A readonly property may be written once, from inside the declaring scope.
     * The double is a different class, so writing it raises an Error — the
     * initializer has to leave it alone even though the class is not readonly.
     */
    public function aReadonlyPropertyOfAWritableClassIsLeftAlone(): void
    {
        $ledger = Understudy::for(PropertyLedger::class);

        Expect::exception(\Error::class)->withMessageContaining('must not be accessed before initialization');

        // Read through an assertion: a bare property read is a statement with
        // no effect, which rector removes as dead code.
        Assert::string($ledger->sealed);
    }

    /**
     * A double has no business inventing a collaborator. Reading the property
     * raises the language's own error, which names the property.
     */
    public function anObjectTypedPropertyIsLeftUninitialized(): void
    {
        $ledger = Understudy::for(PropertyLedger::class);

        Expect::exception(\Error::class)->withMessageContaining('must not be accessed before initialization');

        $ledger->stamp->getTimestamp();
    }

    /**
     * Property hooks and asymmetric visibility exist only from PHP 8.4 and
     * cannot be written from outside — the language either forbids it or routes
     * the write through code the target expects to have run. A `final` property
     * is a different thing: it stops a subclass from redeclaring the property,
     * and stays writable, so it is filled like any other.
     *
     * The fixture is built by eval because its syntax is a parse error on 8.3 —
     * a file carrying it would take the whole suite down there, not skip a test.
     */
    public function hookedAndAsymmetricPropertiesAreLeftAloneButFinalOnesAreNot(): void
    {
        if (PHP_VERSION_ID < 80400) {
            // Nothing to skip: the language cannot express any of them yet.
            Assert::false(method_exists(\ReflectionProperty::class, 'hasHooks'));

            return;
        }

        $class = 'HookedLedger_' . PHP_VERSION_ID;

        eval(sprintf(
            'namespace %s; class %s { public int $plain; public int $hooked { get => 1; } '
            . 'final public int $sealed; public private(set) int $restricted; }',
            __NAMESPACE__,
            $class,
        ));

        /** @var class-string $fqcn */
        $fqcn = __NAMESPACE__ . '\\' . $class;

        Assert::same(PropertyDefaults::forTarget(new \ReflectionClass($fqcn)), ['plain' => 0, 'sealed' => 0]);
    }

    // --- Rejections ---------------------------------------------------------

    /**
     * `final` on a STATIC method rejects nothing: statics are not intercepted,
     * so there is nothing a final one could hide from the double.
     */
    public function aFinalStaticMethodDoesNotRejectTheTarget(): void
    {
        $class = 'FinalStaticHost_' . PHP_VERSION_ID;
        eval(sprintf(
            'namespace %s; class %s { final public static function seal(): int { return 1; } public function plain(): int { return 2; } }',
            __NAMESPACE__,
            $class,
        ));

        /** @var class-string $fqcn */
        $fqcn = __NAMESPACE__ . '\\' . $class;
        $double = Understudy::for($fqcn);

        Assert::same($double->plain(), 0);
    }

    /**
     * The final-member walk reads EVERY method: a final instance method after
     * a perfectly ordinary one still rejects the target.
     */
    public function aFinalMethodAfterAPlainOneStillRejects(): void
    {
        $class = 'LateFinalHost_' . PHP_VERSION_ID;
        eval(sprintf(
            'namespace %s; class %s { public function plain(): int { return 1; } final public function sealed(): int { return 2; } }',
            __NAMESPACE__,
            $class,
        ));

        /** @var class-string $fqcn */
        $fqcn = __NAMESPACE__ . '\\' . $class;

        Expect::exception(UnsupportedTarget::class)
            ->withMessageContaining('::sealed()` is final and cannot be overridden');

        Understudy::for($fqcn);
    }

    public function aFinalClassIsRejected(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `' . FinalLedger::class . "`: the class is final, and bypass is not enabled.\n"
            . "- Preferred: if it implements an interface, double the interface.\n"
            . "- If it is a value object, prefer a real instance.\n"
            . "- If it is a concrete dependency you cannot change, enable bypass before the class is\n"
            . "  first loaded: Understudy::bypassFinals(FinalLedger::class)\n"
            . '- Introducing an interface remains the cleanest long-term fix.',
        );

        Understudy::for(FinalLedger::class);
    }

    public function aNonPrivateFinalMethodRejectsTheWholeTarget(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `' . SealedLedger::class . '`: `' . SealedLedger::class . '::seal()` '
            . 'is final and cannot be overridden, so a double would run the real method against an object '
            . 'whose constructor never ran. Double the interface instead.',
        );

        Understudy::for(SealedLedger::class);
    }

    public function anEnumIsRejected(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `' . Suit::class . '`: an enum cannot be extended. Its cases are '
            . 'the values themselves — pass the case you need, or double the interface the enum implements.',
        );

        Understudy::for(Suit::class);
    }

    public function anInternalClassIsRejected(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `ArrayObject`: an internal class carries state this engine cannot '
            . 'reason about — its constructor is skipped and its native handlers would still run. Wrap it '
            . 'behind an interface of your own.',
        );

        Understudy::for(\ArrayObject::class);
    }

    public function aClassAfterTheFirstTargetIsRejected(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `' . Ledger::class . '`: only the first target may be a class — '
            . 'PHP has single inheritance. Put the class first and keep the rest interfaces.',
        );

        Understudy::for(Bookkeeper::class, Ledger::class);
    }

    public function aTraitIsRejected(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            'Cannot create an understudy for `' . Countable::class . '`: a trait has no instances of its own. '
            . 'Double the class that uses it, or the interface it helps implement.',
        );

        Understudy::for(Countable::class);
    }

    /**
     * A generated class is compiled once per contract set and reused. Rebuilding
     * the blueprint each time would leave the class alone — `class_exists`
     * guards the eval — and quietly hand out a second description of it, which
     * the clone hook resolves doubles through.
     */
    public function aContractCompilesToOneBlueprintAndOneClass(): void
    {
        $first = DoubleFactory::blueprintFor([Bookkeeper::class]);
        $second = DoubleFactory::blueprintFor([Bookkeeper::class]);

        Assert::same($first, $second);
        Assert::same(DoubleFactory::blueprintOfGenerated($first->generatedClass), $first);
        Assert::true((bool) preg_match(
            '/^Rasuvaeff\\\\Understudy\\\\Codegen\\\\Generated\\\\Understudy_[0-9a-f]{16}\\z/',
            $first->generatedClass,
        ));
    }

    // --- Parameter defaults -------------------------------------------------

    /**
     * Each default is compared by calling the method without the argument and
     * reading what was logged: the recorded value is the contract's default or
     * the test fails, whatever the generated source happens to say.
     */
    public function constantEnumAndArrayDefaultsSurviveIntoTheDouble(): void
    {
        $contract = Understudy::for(DefaultsContract::class);

        $contract->withClassConstant();
        $contract->withEnumCase();
        $contract->withGlobalConstant();
        $contract->withArray();
        $contract->withNullableObject();

        Assert::same(Understudy::calls(static fn(): string => $contract->withClassConstant(Arg::any()))[0]->args, ['p-']);
        Assert::same(Understudy::calls(static fn(): string => $contract->withEnumCase(Arg::any()))[0]->args, [Suit::Hearts]);
        Assert::same(Understudy::calls(static fn(): int => $contract->withGlobalConstant(Arg::any()))[0]->args, [PHP_INT_MAX]);
        Assert::same(Understudy::calls(static fn(): array => $contract->withArray(Arg::any()))[0]->args, [['k' => 'p-']]);
        Assert::same(Understudy::calls(static fn(): bool => $contract->withNullableObject(Arg::any()))[0]->args, [null]);
    }

    /**
     * `getDefaultValue()` on a `new` default runs the constructor, so the
     * expression is taken from the parameter's own rendering instead — and it
     * arrives fully qualified, which is what makes it reproducible.
     */
    public function anObjectDefaultIsRebuiltFromItsSourceExpression(): void
    {
        $contract = Understudy::for(ObjectDefaultContract::class);

        $contract->stamped();

        $args = Understudy::calls(static fn(): string => $contract->stamped(Arg::any()))[0]->args;

        Assert::instanceOf($args[0], Stamp::class);
        Assert::same($args[0]->at, 7);
        Assert::same($args[0]->tag, 'x');
    }

    /**
     * Building the double must not evaluate the default, and calling the method
     * without the argument must produce exactly what the contract promises. The
     * counter separates the two: generation leaves it at zero, the call moves it
     * to one.
     */
    public function anObjectNestedInAnArrayDefaultIsNeitherEvaluatedNorLost(): void
    {
        CountingStamp::$constructed = 0;

        $contract = Understudy::for(NestedObjectDefaultContract::class);

        Assert::same(CountingStamp::$constructed, 0);

        $contract->batched();

        Assert::same(CountingStamp::$constructed, 1);

        $args = Understudy::calls(static fn(): int => $contract->batched(Arg::any()))[0]->args;

        Assert::instanceOf($args[0][0], CountingStamp::class);
        Assert::same($args[0][0]->at, 3);
    }

    /**
     * Reflection reports the default as `self::STEP`, and `self` inside the
     * generated class is a class that never had the constant. The value is used
     * instead, so the double still answers what the contract promises.
     */
    public function aConstantTheDoubleCannotNameFallsBackToItsValue(): void
    {
        $contract = Understudy::for(PrivateConstantContract::class);

        $contract->step();

        Assert::same(Understudy::calls(static fn(): int => $contract->step(Arg::any()))[0]->args, [4]);
    }

    // --- Cloning ------------------------------------------------------------

    /**
     * `__clone()` runs on the copy and PHP gives it no reference to the
     * original, so the copy is owned by whoever cloned it — the same rule
     * `for()` follows. A Fiber that clones a double owns the copy, and the
     * outer context cannot configure or verify it.
     */
    public function aCloneBelongsToTheContextThatMadeIt(): void
    {
        $ledger = Understudy::for(Ledger::class);
        $copy = null;

        $fiber = new \Fiber(static function () use ($ledger, &$copy): void {
            $copy = clone $ledger;
        });
        $fiber->start();

        Assert::instanceOf($copy, Ledger::class);

        Expect::exception(ContextOwnershipViolation::class)->withMessageContaining(
            'This understudy belongs to a different runtime context',
        );

        when(static fn(): string => $copy->describe())->returns('from outside');
    }

    /**
     * A copy is a double of its own. Sharing the original's expectations would
     * let a copy the code under test made satisfy the test's setup; sharing its
     * call log would let it satisfy a verification it never took part in.
     */
    public function aClonedDoubleKeepsTheContractAndNothingElse(): void
    {
        $ledger = Understudy::for(Ledger::class);
        when(static fn(): string => $ledger->describe())->returns('original');

        $copy = clone $ledger;

        Assert::same($ledger->describe(), 'original');
        Assert::same($copy->describe(), '');
        Assert::same(count(Understudy::calls(static fn(): string => $copy->describe())), 1);
        Assert::same(count(Understudy::calls(static fn(): string => $ledger->describe())), 1);
    }
}
