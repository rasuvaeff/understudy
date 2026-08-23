<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Defaults;

use Rasuvaeff\Understudy\Defaults\DefaultFactories;
use Rasuvaeff\Understudy\Exception\AmbiguousDefaultFactory;
use Rasuvaeff\Understudy\Exception\InvalidDefaultValue;
use Rasuvaeff\Understudy\Tests\Fixture\Def\Audited;
use Rasuvaeff\Understudy\Tests\Fixture\Def\AuditedLogger;
use Rasuvaeff\Understudy\Tests\Fixture\Def\Concrete;
use Rasuvaeff\Understudy\Tests\Fixture\Def\Logger;
use Rasuvaeff\Understudy\Tests\Fixture\Def\NullLogger;
use Rasuvaeff\Understudy\Tests\Fixture\Def\Timestamped;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

/**
 * The registry on its own. Driving it through a double exercises the paths a
 * double happens to take; these reach the ones it does not — an empty registry,
 * a factory that answers null, a subclass two steps from its registration.
 */
#[Test]
#[Covers(DefaultFactories::class)]
final class DefaultFactoriesUnitTest
{
    public function anEmptyRegistryAnswersNothing(): void
    {
        Assert::null((new DefaultFactories())->valueFor(Logger::class));
    }

    public function anUnrelatedRegistrationAnswersNothing(): void
    {
        $registry = new DefaultFactories();
        $registry->register(Timestamped::class, static fn(): AuditedLogger => new AuditedLogger());

        Assert::null($registry->valueFor(NullLogger::class));
    }

    /**
     * Wrapped in a one-element list on purpose: a factory is allowed to answer
     * with something falsy, and "no factory" has to stay distinguishable from
     * "the factory said this".
     */
    public function ananswerIsWrappedSoThatNoFactoryStaysDistinguishable(): void
    {
        $registry = new DefaultFactories();
        $logger = new NullLogger();
        $registry->register(Logger::class, static fn(): NullLogger => $logger);

        Assert::same($registry->valueFor(Logger::class), [$logger]);
    }

    public function aRegistrationOnASupertypeAnswersForTheSubtype(): void
    {
        $registry = new DefaultFactories();
        $registry->register(Logger::class, static fn(): AuditedLogger => new AuditedLogger());

        $answer = $registry->valueFor(AuditedLogger::class);

        Assert::notNull($answer);
        Assert::instanceOf($answer[0], AuditedLogger::class);
    }

    /**
     * `Audited` is one step from `AuditedLogger`, `Logger` two — through
     * `Audited`. A flattened view of the graph would call them both one step
     * away and refuse a question that has an answer.
     */
    public function theNearerOfTwoRelatedRegistrationsWins(): void
    {
        $registry = new DefaultFactories();
        $registry->register(Logger::class, static fn(): AuditedLogger => new AuditedLogger());
        $nearer = new class extends AuditedLogger {};
        $registry->register(Audited::class, static fn(): AuditedLogger => $nearer);

        Assert::same($registry->valueFor(AuditedLogger::class), [$nearer]);
    }

    public function twoRegistrationsAtTheSameDistanceAreRefused(): void
    {
        $registry = new DefaultFactories();
        $registry->register(Audited::class, static fn(): AuditedLogger => new AuditedLogger());
        $registry->register(Timestamped::class, static fn(): AuditedLogger => new AuditedLogger());

        Expect::exception(AmbiguousDefaultFactory::class)->withMessage(
            "More than one default factory is equally close to `" . AuditedLogger::class . "`: `"
            . Audited::class . '`, `' . Timestamped::class . "`.\n"
            . 'Register one for `' . AuditedLogger::class . '` itself — resolution by distance is what keeps '
            . 'the answer independent of the order the factories were registered in, and a tie has no order '
            . 'to fall back on.',
        );

        $registry->valueFor(AuditedLogger::class);
    }

    public function anExactRegistrationIsFoundBeforeTheGraphIsWalked(): void
    {
        $registry = new DefaultFactories();
        $registry->register(Audited::class, static fn(): AuditedLogger => new AuditedLogger());
        $registry->register(Timestamped::class, static fn(): AuditedLogger => new AuditedLogger());
        $exact = new class extends AuditedLogger {};
        $registry->register(AuditedLogger::class, static fn(): AuditedLogger => $exact);

        Assert::same($registry->valueFor(AuditedLogger::class), [$exact]);
    }

    public function aLeadingBackslashIsTheSameContractOnBothSides(): void
    {
        $registry = new DefaultFactories();
        $logger = new NullLogger();
        $registry->register('\\' . Logger::class, static fn(): NullLogger => $logger);

        Assert::same($registry->valueFor(Logger::class), [$logger]);
        Assert::same($registry->valueFor('\\' . Logger::class), [$logger]);
    }

    public function aWrongTypedAnswerIsRefused(): void
    {
        $registry = new DefaultFactories();
        $registry->register(Logger::class, static fn(): Concrete => new Concrete());

        Expect::exception(InvalidDefaultValue::class)
            ->withMessageContaining('produced a `' . Concrete::class . '`');

        $registry->valueFor(Logger::class);
    }

    /**
     * A class with no parent and no interfaces has no graph to walk; the search
     * has to end rather than look for a level that is not there.
     */
    public function aTypeWithNoAncestorsSimplyHasNoFactory(): void
    {
        $registry = new DefaultFactories();
        $registry->register(Logger::class, static fn(): NullLogger => new NullLogger());

        Assert::null($registry->valueFor(Concrete::class));
    }
}
