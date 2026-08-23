<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Exception\CannotWire;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\AbstractSubject;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\CatalogService;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\CountingDefault;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\CountingRepository;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\IntersectionDependency;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\NoConstructor;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\NullableDependency;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\NullableScalar;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\ObjectDefaultSubject;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\PrivateConstructor;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\ReferenceConstructor;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\Reporter;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\Repository;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\ScalarWithoutDefault;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\TwoObjectUnion;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\VariadicService;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Understudy\Wiring\Wire;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(Wire::class)]
final class WireTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    // --- The happy path ------------------------------------------------------

    public function everyObjectDependencyBecomesADoubleTheTestCanDrive(): void
    {
        ['sut' => $service, 'doubles' => $doubles] = Understudy::wire(CatalogService::class);

        Assert::instanceOf($service, CatalogService::class);
        Assert::same(array_keys($doubles), ['repository', 'reporter']);

        $repository = $doubles['repository'];
        when(static fn(): string => $repository->find(1))->returns('Dune');

        Assert::same($service->lookup(1), 'Dune/10');
    }

    /**
     * A scalar with a default is the value the real caller would have got. It
     * is not a collaborator, so no double appears for it.
     */
    public function aScalarWithADefaultIsPassedAsDeclared(): void
    {
        ['sut' => $service, 'doubles' => $doubles] = Understudy::wire(CatalogService::class);

        Assert::false(array_key_exists('limit', $doubles));
        $repository = $doubles['repository'];
        when(static fn(): string => $repository->find(2))->returns('x');

        Assert::same($service->lookup(2), 'x/10');
    }

    /**
     * A nullable dependency still gets a double: `null` is a value the test can
     * ask for with an override, and a silent `null` would be a collaborator
     * quietly missing.
     */
    public function aNullableDependencyStillGetsADouble(): void
    {
        ['sut' => $subject, 'doubles' => $doubles] = Understudy::wire(NullableDependency::class);

        Assert::instanceOf($subject->repository, Repository::class);
        Assert::same($doubles['repository'], $subject->repository);
    }

    public function anIntersectionBecomesOneDoubleOfBothContracts(): void
    {
        ['sut' => $subject] = Understudy::wire(IntersectionDependency::class);

        Assert::instanceOf($subject->both, Repository::class);
        Assert::instanceOf($subject->both, \Countable::class);
    }

    /**
     * A variadic tail is optional by definition. Inventing entries for it would
     * invent collaborators the constructor never asked for.
     */
    public function aVariadicTailIsLeftEmpty(): void
    {
        ['sut' => $subject, 'doubles' => $doubles] = Understudy::wire(VariadicService::class);

        Assert::same($subject->tags, []);
        Assert::same(array_keys($doubles), ['repository']);
    }

    public function aSubjectWithoutAConstructorIsJustBuilt(): void
    {
        ['sut' => $subject, 'doubles' => $doubles] = Understudy::wire(NoConstructor::class);

        Assert::same($subject->label(), 'plain');
        Assert::same($doubles, []);
    }

    /**
     * A parameter with a default is omitted from the call, so PHP applies the
     * constructor's own. Reading it would evaluate it, and `= new Foo()` is a
     * legal default whose constructor has no business running during wiring.
     */
    public function anObjectDefaultIsNeverEvaluatedWhileWiring(): void
    {
        CountingDefault::$constructed = 0;

        ['sut' => $subject, 'doubles' => $doubles] = Understudy::wire(ObjectDefaultSubject::class);

        // Once, by PHP, when the constructor ran — not by wire() beforehand.
        Assert::same(CountingDefault::$constructed, 1);
        Assert::instanceOf($subject->stamp, CountingDefault::class);
        Assert::same(array_keys($doubles), ['repository']);
    }

    // --- Overrides -----------------------------------------------------------

    /**
     * An override is already in the caller's hands, so it is not repeated in
     * `doubles` — that map is what wire() created.
     */
    public function anOverrideReplacesTheDoubleAndIsNotReported(): void
    {
        $reporter = Understudy::for(Reporter::class);

        ['sut' => $service, 'doubles' => $doubles] = Understudy::wire(
            CatalogService::class,
            ['reporter' => $reporter, 'limit' => 3],
        );

        Assert::same(array_keys($doubles), ['repository']);

        $repository = $doubles['repository'];
        when(static fn(): string => $repository->find(7))->returns('Ubik');

        Assert::same($service->lookup(7), 'Ubik/3');
        Assert::same(count(Understudy::calls(static fn() => $reporter->report('Ubik'))), 1);
    }

    public function anUnknownOverrideNamesWhatTheConstructorTakes(): void
    {
        Expect::exception(CannotWire::class)
            ->withMessageContaining('there is no constructor parameter named `repostory`')
            ->withMessageContaining('The constructor takes: $repository, $reporter, $limit');

        Understudy::wire(CatalogService::class, ['repostory' => Understudy::for(Repository::class)]);
    }

    public function anIncompatibleOverrideIsRefusedBeforeTheConstructorRuns(): void
    {
        Expect::exception(CannotWire::class)
            ->withMessageContaining('the override for `$repository` is a `stdClass`')
            ->withMessageContaining('the constructor declares `' . Repository::class . '`');

        Understudy::wire(CatalogService::class, ['repository' => new \stdClass()]);
    }

    /**
     * A nullable scalar has an answer that is not a guess: `null` is the only
     * value it can hold without one. A nullable *object* still gets a double,
     * because `null` there would be a collaborator quietly missing.
     */
    public function aNullableScalarWithoutADefaultBecomesNull(): void
    {
        ['sut' => $subject, 'doubles' => $doubles] = Understudy::wire(NullableScalar::class);

        Assert::null($subject->name);
        Assert::instanceOf($subject->repository, Repository::class);
        Assert::same(array_keys($doubles), ['repository']);
    }

    public function nullIsAcceptedAsAnOverrideForANullableParameter(): void
    {
        ['sut' => $subject] = Understudy::wire(NullableDependency::class, ['repository' => null]);

        Assert::null($subject->repository);
    }

    public function nullIsRefusedAsAnOverrideForANonNullableParameter(): void
    {
        Expect::exception(CannotWire::class)
            ->withMessageContaining('the override for `$repository` is a `null`');

        Understudy::wire(CatalogService::class, ['repository' => null]);
    }

    // --- Refusals ------------------------------------------------------------

    public function aUnionOfSeveralObjectTypesIsRefused(): void
    {
        Expect::exception(CannotWire::class)
            ->withMessageContaining('nothing can be decided for `$either`')
            ->withMessageContaining('picking one of them would be a guess');

        Understudy::wire(TwoObjectUnion::class);
    }

    public function aScalarWithoutADefaultIsRefused(): void
    {
        Expect::exception(CannotWire::class)
            ->withMessageContaining('nothing can be decided for `$name` (`string`)')
            ->withMessageContaining("Understudy::wire(ScalarWithoutDefault::class, ['name' => \$value])");

        Understudy::wire(ScalarWithoutDefault::class);
    }

    public function aByReferenceConstructorParameterIsRefused(): void
    {
        Expect::exception(CannotWire::class)
            ->withMessageContaining('`$sink` is taken by reference')
            ->withMessageContaining('Overrides are values');

        Understudy::wire(ReferenceConstructor::class);
    }

    public function aPrivateConstructorIsRefused(): void
    {
        Expect::exception(CannotWire::class)
            ->withMessageContaining('its constructor is private')
            ->withMessageContaining('Use the named constructor the class offers');

        Understudy::wire(PrivateConstructor::class);
    }

    public function anAbstractSubjectIsRefused(): void
    {
        Expect::exception(CannotWire::class)->withMessageContaining('it is abstract, so it cannot be instantiated');

        Understudy::wire(AbstractSubject::class);
    }

    public function anInterfaceSubjectIsRefused(): void
    {
        Expect::exception(CannotWire::class)
            ->withMessageContaining('it is an interface')
            ->withMessageContaining('the subject itself is not a double');

        Understudy::wire(Repository::class);
    }

    public function anEnumSubjectIsRefused(): void
    {
        Expect::exception(CannotWire::class)->withMessageContaining('it is an enum');

        Understudy::wire(Fixture\Suit::class);
    }

    public function aMissingSubjectIsRefused(): void
    {
        Expect::exception(CannotWire::class)->withMessageContaining('there is no such class');

        // Deliberately not a real class; psalm analyses src/ only, so naming
        // one here raises nothing to suppress.
        Understudy::wire('Rasuvaeff\\Understudy\\Tests\\Fixture\\Wire\\Nope');
    }

    public function aCountingIntersectionContractIsDoubledAsOne(): void
    {
        $double = Understudy::for(CountingRepository::class);

        Assert::instanceOf($double, Repository::class);
        Assert::instanceOf($double, \Countable::class);
    }
}
