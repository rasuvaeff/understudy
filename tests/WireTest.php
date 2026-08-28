<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\Understudy\Exception\CannotWire;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\AbstractSubject;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\ArrayVariadic;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\BoolVariadic;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\CallableVariadic;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\CatalogService;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\CountingDefault;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\CountingRepository;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\FalseVariadic;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\FloatOrObjectUnion;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\FloatVariadicService;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\IntersectionDependency;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\IntVariadic;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\IterableVariadic;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\MixedVariadic;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\NoConstructor;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\NullableDependency;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\NullableScalar;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\ObjectDefaultSubject;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\ObjectVariadic;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\PrivateConstructor;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\ReferenceConstructor;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\Reporter;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\Repository;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\ScalarWithoutDefault;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\TrueVariadic;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\TwoObjectUnion;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\VariadicObjectDefaultSubject;
use Rasuvaeff\Understudy\Tests\Fixture\Wire\VariadicService;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Understudy\Wiring\Wire;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(Wire::class)]
#[Covers(CannotWire::class)]
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

    public function aVariadicOverrideIsExpandedIntoTheConstructorTail(): void
    {
        ['sut' => $subject] = Understudy::wire(
            VariadicService::class,
            ['tags' => ['one', 'two']],
        );

        Assert::same($subject->priority, 10);
        Assert::same($subject->tags, ['one', 'two']);
    }

    public function aVariadicOverrideRejectsAnInvalidElementBeforeConstruction(): void
    {
        Expect::exception(CannotWire::class)
            ->withMessageContaining('list<string>')
            ->withMessageContaining('int');

        Understudy::wire(VariadicService::class, ['tags' => ['one', 2]]);
    }

    public function aVariadicOverrideRejectsANonListBeforeConstruction(): void
    {
        Expect::exception(CannotWire::class)
            ->withMessageContaining('list<string>')
            ->withMessageContaining('string');

        Understudy::wire(VariadicService::class, ['tags' => 'one']);
    }

    public function anObjectDefaultBeforeAVariadicTailIsMaterializedOnce(): void
    {
        CountingDefault::$constructed = 0;

        ['sut' => $subject] = Understudy::wire(
            VariadicObjectDefaultSubject::class,
            ['tags' => ['one']],
        );

        Assert::same(CountingDefault::$constructed, 1);
        Assert::instanceOf($subject->stamp, CountingDefault::class);
        Assert::same($subject->tags, ['one']);
    }

    /**
     * Every branch of the override type check, including the ones a scalar
     * variadic is the only way to reach: `rejectIncompatibleOverride()` hands
     * a plain builtin straight to PHP, so a tail is where they are decided.
     *
     * @param class-string $subject
     * @param list<mixed>  $accepted
     */
    #[DataProvider('variadicTypeProvider')]
    public function aVariadicOverrideIsCheckedAgainstItsDeclaredType(
        string $subject,
        array $accepted,
        mixed $rejected,
        string $rejectedType,
        string $declared,
    ): void {
        ['sut' => $built] = Understudy::wire($subject, ['values' => $accepted]);

        Assert::same($built->values, $accepted);

        Expect::exception(CannotWire::class)
            ->withMessageContaining('is a `' . $rejectedType . '`')
            ->withMessageContaining('declares `list<' . $declared . '>`');

        Understudy::wire($subject, ['values' => [$rejected]]);
    }

    /**
     * @return iterable<string, array{class-string, list<mixed>, mixed, string, string}>
     */
    public static function variadicTypeProvider(): iterable
    {
        yield 'bool' => [BoolVariadic::class, [true, false], 1, 'int', 'bool'];
        yield 'true' => [TrueVariadic::class, [true], false, 'bool', 'true'];
        yield 'false' => [FalseVariadic::class, [false], true, 'bool', 'false'];
        yield 'int' => [IntVariadic::class, [1, 2], '1', 'string', 'int'];
        yield 'array' => [ArrayVariadic::class, [[], ['a']], 'a', 'string', 'array'];
        yield 'object' => [ObjectVariadic::class, [new \stdClass()], 'a', 'string', 'object'];
        yield 'callable' => [CallableVariadic::class, ['strlen'], 1, 'int', 'callable'];
        yield 'iterable' => [IterableVariadic::class, [[], ['a']], 'a', 'string', 'iterable'];
    }

    public function aMixedVariadicOverrideAcceptsAnything(): void
    {
        ['sut' => $built] = Understudy::wire(MixedVariadic::class, ['values' => [1, 'a', null]]);

        Assert::same($built->values, [1, 'a', null]);
    }

    /**
     * PHP widens `int` to `float` at the call boundary even under
     * `declare(strict_types=1)`, so refusing one here would refuse a call the
     * subject would have accepted.
     */
    public function anIntIsAcceptedWhereAFloatIsDeclared(): void
    {
        ['sut' => $subject] = Understudy::wire(
            FloatVariadicService::class,
            ['rates' => [1, 2.5]],
        );

        Assert::same($subject->rates, [1.0, 2.5]);
    }

    public function anIntIsAcceptedInAUnionBranchDeclaringFloat(): void
    {
        ['sut' => $subject] = Understudy::wire(FloatOrObjectUnion::class, ['either' => 3]);

        Assert::same($subject->either, 3.0);
    }

    public function aStringIsRefusedInAUnionOfFloatAndAnObject(): void
    {
        Expect::exception(CannotWire::class)->withMessageContaining('string');

        Understudy::wire(FloatOrObjectUnion::class, ['either' => 'three']);
    }

    public function aUnionOverrideIsCheckedBeforeConstruction(): void
    {
        Expect::exception(CannotWire::class)
            ->withMessageContaining(Repository::class . '|' . Reporter::class)
            ->withMessageContaining('stdClass');

        Understudy::wire(TwoObjectUnion::class, ['either' => new \stdClass()]);
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
            ->withMessage(
                'Cannot wire `' . CatalogService::class . '`: the override for `$repository` is a `stdClass`, '
                . 'and the constructor declares `' . Repository::class . "`.\n"
                . 'The check happens before the constructor runs, so a wrong type is reported here rather '
                . 'than as a TypeError from inside the subject.',
            );

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
        Expect::exception(CannotWire::class)->withMessage(
            'Cannot wire `' . ReferenceConstructor::class . "`: `\$sink` is taken by reference.\n"
            . 'Overrides are values, and passing one would quietly promise a reference semantics wire() does '
            . 'not have. Build the subject yourself.',
        );

        Understudy::wire(ReferenceConstructor::class);
    }

    public function aPrivateConstructorIsRefused(): void
    {
        Expect::exception(CannotWire::class)
            ->withMessage(
                'Cannot wire `' . PrivateConstructor::class . "`: its constructor is private.\n"
                . 'Use the named constructor the class offers, or build the subject yourself and double '
                . 'its dependencies with Understudy::for().',
            );

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
            ->withMessage(
                'Cannot wire `' . Repository::class . "`: it is an interface.\n"
                . 'wire() builds a real subject out of doubled dependencies; the subject itself is not '
                . 'a double.',
            );

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
