<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Defaults;

use Rasuvaeff\Understudy\Defaults\TypeDefaultResolver;
use Rasuvaeff\Understudy\Exception\NoDefaultValue;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Tests\Fixture\Dnf\Alpha;
use Rasuvaeff\Understudy\Tests\Fixture\Dnf\Beta;
use Rasuvaeff\Understudy\Tests\Fixture\Dnf\Delta;
use Rasuvaeff\Understudy\Tests\Fixture\Dnf\Gamma;
use Rasuvaeff\Understudy\Tests\Fixture\Dnf\Shapes;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * A DNF return type is `(A&B)|C`, and every part of that grammar has to
 * survive the trip from Reflection to an answer.
 *
 * The shapes are exercised through a real double rather than the resolver's
 * own signature, because the bug this file exists for was in the string
 * Reflection hands over: parentheses were taken off the whole type instead of
 * off each branch, which cut `(A&B)|(C&D)` in half and left the halves
 * unsplittable.
 *
 * @internal
 */
#[Test]
#[Covers(TypeDefaultResolver::class)]
#[Covers(Runtime::class)]
#[Covers(NoDefaultValue::class)]
final class IntersectionDefaultTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    public function aPlainIntersectionBecomesADoubleOfEveryAtom(): void
    {
        $answer = Understudy::for(Shapes::class)->plain();

        Assert::instanceOf($answer, Alpha::class);
        Assert::instanceOf($answer, Beta::class);
    }

    public function nullStillWinsOverAnIntersection(): void
    {
        // The union rule is unchanged: a `null` branch answers first, so a
        // nullable return does not silently start handing back an object.
        Assert::null(Understudy::for(Shapes::class)->withNull());
    }

    public function aScalarBranchStillWinsOverAnIntersection(): void
    {
        // Preferring something scalar-safe is the older rule and it stays:
        // an intersection is object-shaped, and answering with an object
        // where `''` would do invents a collaborator the test never asked for.
        Assert::same(Understudy::for(Shapes::class)->withScalar(), '');
    }

    public function aUnionOfNothingButIntersectionsAnswersWithTheFirst(): void
    {
        // Nothing scalar-safe to prefer, so the refusal would have been
        // pointless: the engine can build this.
        $answer = Understudy::for(Shapes::class)->twoIntersections();

        Assert::instanceOf($answer, Alpha::class);
        Assert::instanceOf($answer, Beta::class);
        Assert::false($answer instanceof Gamma);
        Assert::false($answer instanceof Delta);
    }

    public function aPlainClassBranchIsPreferredToAnIntersection(): void
    {
        $answer = Understudy::for(Shapes::class)->withClassBranch();

        Assert::instanceOf($answer, Gamma::class);
        Assert::false($answer instanceof Alpha);
    }

    public function anUndoublableBranchIsSkippedRatherThanTaken(): void
    {
        // `Sealed` is final, so it cannot be answered with; the intersection
        // behind it has to get its turn instead of the whole type failing.
        $answer = Understudy::for(Shapes::class)->afterAnUndoublableBranch();

        Assert::instanceOf($answer, Alpha::class);
        Assert::instanceOf($answer, Beta::class);
    }
}
