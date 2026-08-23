<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Defaults\DefaultFactories;
use Rasuvaeff\Understudy\Defaults\TypeDefaultResolver;
use Rasuvaeff\Understudy\Exception\AmbiguousDefaultFactory;
use Rasuvaeff\Understudy\Exception\InvalidDefaultValue;
use Rasuvaeff\Understudy\Exception\NoDefaultValue;
use Rasuvaeff\Understudy\Tests\Fixture\Def\Audited;
use Rasuvaeff\Understudy\Tests\Fixture\Def\AuditedLogger;
use Rasuvaeff\Understudy\Tests\Fixture\Def\Concrete;
use Rasuvaeff\Understudy\Tests\Fixture\Def\Logger;
use Rasuvaeff\Understudy\Tests\Fixture\Def\NullLogger;
use Rasuvaeff\Understudy\Tests\Fixture\Def\Sealed;
use Rasuvaeff\Understudy\Tests\Fixture\Def\Timestamped;
use Rasuvaeff\Understudy\Tests\Fixture\Def\Workspace;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Test]
#[Covers(DefaultFactories::class)]
#[Covers(TypeDefaultResolver::class)]
final class DefaultFactoriesTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    // --- Registered factories ------------------------------------------------

    public function aRegisteredFactoryAnswersInsteadOfANestedDouble(): void
    {
        Understudy::defaults(Logger::class, static fn(): NullLogger => new NullLogger());

        $workspace = Understudy::for(Workspace::class);

        Assert::instanceOf($workspace->logger(), NullLogger::class);
    }

    /**
     * The nearest registered ancestor wins, and "nearest" is a distance in the
     * type graph rather than the order the factories were registered in.
     * `AuditedLogger` implements `Audited`, which extends `Logger`: one step to
     * `Audited`, two to `Logger`.
     */
    public function theNearestRegisteredAncestorWins(): void
    {
        Understudy::defaults(Logger::class, static fn(): AuditedLogger => new AuditedLogger());
        Understudy::defaults(Audited::class, static fn(): AuditedLogger => new class extends AuditedLogger {});

        $workspace = Understudy::for(Workspace::class);
        $answered = $workspace->auditor();

        Assert::true($answered::class !== AuditedLogger::class);
        Assert::instanceOf($answered, AuditedLogger::class);
    }

    public function registrationOrderDoesNotDecide(): void
    {
        Understudy::defaults(Audited::class, static fn(): AuditedLogger => new class extends AuditedLogger {});
        Understudy::defaults(Logger::class, static fn(): AuditedLogger => new AuditedLogger());

        $workspace = Understudy::for(Workspace::class);

        // Same answer as the reverse registration order above.
        Assert::true($workspace->auditor()::class !== AuditedLogger::class);
    }

    /**
     * `Audited` and `Timestamped` are both one step from `AuditedLogger` and
     * unrelated to each other. There is no order to fall back on that a reader
     * could predict, so neither is chosen.
     */
    public function aTieBetweenEquallyCloseFactoriesIsRefused(): void
    {
        Understudy::defaults(Audited::class, static fn(): AuditedLogger => new AuditedLogger());
        Understudy::defaults(Timestamped::class, static fn(): AuditedLogger => new AuditedLogger());

        $workspace = Understudy::for(Workspace::class);

        Expect::exception(AmbiguousDefaultFactory::class)
            ->withMessageContaining('More than one default factory is equally close to `' . AuditedLogger::class . '`')
            ->withMessageContaining('`' . Audited::class . '`')
            ->withMessageContaining('`' . Timestamped::class . '`');

        $workspace->auditor();
    }

    public function anExactRegistrationBeatsAnAncestorTie(): void
    {
        Understudy::defaults(Audited::class, static fn(): AuditedLogger => new AuditedLogger());
        Understudy::defaults(Timestamped::class, static fn(): AuditedLogger => new AuditedLogger());
        Understudy::defaults(AuditedLogger::class, static fn(): AuditedLogger => new class extends AuditedLogger {});

        $workspace = Understudy::for(Workspace::class);

        Assert::true($workspace->auditor()::class !== AuditedLogger::class);
    }

    public function aFactoryProducingTheWrongTypeIsRefused(): void
    {
        Understudy::defaults(Logger::class, static fn(): \stdClass => new \stdClass());

        $workspace = Understudy::for(Workspace::class);

        Expect::exception(InvalidDefaultValue::class)
            ->withMessage(
                "The default factory registered for `" . Logger::class . "` produced a `stdClass`.\n"
                . 'A factory has to return something the requested type can hold, or the double answers with '
                . 'a value the code under test cannot use.',
            );

        $workspace->logger();
    }

    /**
     * A registration outranks the builtin table: a test that said what a
     * `Countable` should be means it for that type, not only for types the
     * table has no answer for.
     */
    public function aRegistrationOutranksTheBuiltinTable(): void
    {
        Understudy::defaults(\Countable::class, static fn(): \ArrayObject => new \ArrayObject([1, 2]));

        $workspace = Understudy::for(Workspace::class);

        Assert::same(count($workspace->counter()), 2);
    }

    /**
     * A contract written with a leading backslash is the same contract.
     * Registering `\Foo::class` and asking for `Foo` has to find it, or the
     * registry answers differently depending on how the test spelled it.
     */
    public function aLeadingBackslashIsNotADifferentContract(): void
    {
        Understudy::defaults('\\' . Logger::class, static fn(): NullLogger => new NullLogger());

        $workspace = Understudy::for(Workspace::class);

        Assert::instanceOf($workspace->logger(), NullLogger::class);
    }

    /**
     * Registration works for a concrete class, not only an interface — the
     * lookup happens before the builtin table for anything that is a type at
     * all.
     */
    public function aConcreteClassCanBeRegistered(): void
    {
        Understudy::defaults(Concrete::class, static fn(): Concrete => new class extends Concrete {
            #[\Override]
            public function value(): int
            {
                return 42;
            }
        });

        $workspace = Understudy::for(Workspace::class);

        Assert::same($workspace->concrete()->value(), 42);
    }

    /**
     * Three candidates, not two: the message has to name all of them, or a
     * reader fixes one tie and meets the next.
     */
    public function everyCandidateInATieIsNamed(): void
    {
        Understudy::defaults(Audited::class, static fn(): AuditedLogger => new AuditedLogger());
        Understudy::defaults(Timestamped::class, static fn(): AuditedLogger => new AuditedLogger());

        $workspace = Understudy::for(Workspace::class);

        try {
            $workspace->auditor();
            Assert::fail('the tie should have been refused');
        } catch (AmbiguousDefaultFactory $refused) {
            Assert::string($refused->getMessage())->contains(Audited::class);
            Assert::string($refused->getMessage())->contains(Timestamped::class);
        }
    }

    // --- The depth-1 double --------------------------------------------------

    /**
     * Without a registration, a doublable return type becomes a double of its
     * own — usable, and configurable by the same test.
     */
    public function anUnregisteredContractBecomesADoubleOfItsOwn(): void
    {
        $workspace = Understudy::for(Workspace::class);
        $logger = $workspace->logger();

        Assert::instanceOf($logger, Logger::class);
        Assert::same(count(Understudy::calls(static fn() => $logger->log('x'))), 0);
    }

    /**
     * Depth stops at one. The nested double answers from the same table, so a
     * chain would grow silently; one level keeps a test moving, and more is a
     * design the test should state out loud.
     */
    public function aFinalReturnTypeStillHasNoSafeDefault(): void
    {
        $workspace = Understudy::for(Workspace::class);

        Expect::exception(NoDefaultValue::class)->withMessageContaining(NullLogger::class);

        $workspace->sealed();
    }

    /**
     * A builtin return type never consults the registry. Asking it would mean
     * reflecting on `int`, which is not a class — and a registered factory for
     * some contract has nothing to say about a scalar anyway.
     */
    public function aBuiltinTypeIsAnsweredByTheTableEvenWithARegistryPresent(): void
    {
        Understudy::defaults(Logger::class, static fn(): NullLogger => new NullLogger());

        $workspace = Understudy::for(Workspace::class);

        Assert::same($workspace->counted(), 0);
    }

    /**
     * Every branch refused means the union is refused, and the message names
     * the whole type rather than whichever branch happened to be tried last.
     */
    public function aUnionWhereNoBranchHasAnAnswerIsRefused(): void
    {
        $workspace = Understudy::for(Workspace::class);

        Expect::exception(NoDefaultValue::class)
            ->withMessageContaining(NullLogger::class)
            ->withMessageContaining(Sealed::class);

        $workspace->hopeless();
    }

    /**
     * A DNF union keeps its parentheses while being split: `(A&B)|null` is two
     * branches, not four fragments.
     */
    public function aDnfUnionIsSplitIntoItsRealBranches(): void
    {
        $workspace = Understudy::for(Workspace::class);

        Assert::null($workspace->eitherWay());
        Assert::same($workspace->eitherOrText(), '');
    }

    // --- Context ownership ---------------------------------------------------

    /**
     * The registry belongs to the context, so a sibling Fiber does not see it
     * and `reset()` drops it with the test.
     */
    public function aSiblingFiberDoesNotSeeTheRegistration(): void
    {
        Understudy::defaults(Logger::class, static fn(): NullLogger => new NullLogger());

        $seen = null;

        $fiber = new \Fiber(static function () use (&$seen): void {
            $workspace = Understudy::for(Workspace::class);
            $seen = $workspace->logger();
        });
        $fiber->start();

        Assert::false($seen instanceof NullLogger);
        Assert::instanceOf($seen, Logger::class);
    }

    public function resetDropsTheRegistry(): void
    {
        Understudy::defaults(Logger::class, static fn(): NullLogger => new NullLogger());
        Understudy::reset();

        $workspace = Understudy::for(Workspace::class);

        Assert::false($workspace->logger() instanceof NullLogger);
    }
}
