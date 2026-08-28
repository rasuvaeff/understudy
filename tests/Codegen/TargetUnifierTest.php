<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Codegen;

use Rasuvaeff\Understudy\Codegen\MethodSignature;
use Rasuvaeff\Understudy\Codegen\TargetUnifier;
use Rasuvaeff\Understudy\Codegen\TypeRenderer;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Runtime\Absent;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\AbstractOwnStatic;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\AbstractStaticFromInterface;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\AggregateUnion;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ArityOne;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ArityTwo;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ArrayAll;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ArrayObjectValue;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\BetaOnly;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\BoolFlag;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\BothIterableCountable;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\CallableFactory;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ClassUnionFirst;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ClosureFactory;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ConstantDefaultBase;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ConstantDefaults;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ConstantsInterface;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\CountableValue;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\DnfParam;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\DnfParamToo;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\FeederByRef;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\FeederByValue;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\FixedArityTwo;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\GeneratorAll;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\InstanceDescribe;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\InterfaceUnionSecond;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\InterfaceValue;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\IntersectedInt;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\IntersectedPair;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\IntersectionAlpha;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\IntersectionBeta;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\IntReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\IntTailPlain;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\IterableAll;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\KnownConstants;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\MixedTail;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\MixedValue;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\MixedWriter;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\Narrower;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\NarrowPartsUnion;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\NarrowReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\NeverValue;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\NullableParam;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\NullableString;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ObjectTail;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ObjectValueToo;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\PrimaryNamed;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ReaderInt;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ReaderString;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ReaderStringy;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ReaderUntyped;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\SecondaryNamed;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\SelfReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\SelfValue;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\Showcase;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\Sibling;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\SlotsByRef;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\SlotsByValue;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticIntPartsContract;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticMixedPartsContract;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticOptionalContract;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPartsContract;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingAsInstanceContract;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingByValueReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingByValueSlot;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingContract;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingExtraRequired;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingFixedInsteadOfTail;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingFixedThenTail;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingIntThenTail;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingNarrowerParameter;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingOptional;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingOptionalInsteadOfTail;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingProtected;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingRequiredParameter;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingTooFewParameters;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingTypedReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingVariadicTail;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticPingWiderParameter;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticReferenceReturnContract;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticSlotContract;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticStringReturnContract;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticTailContract;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticUnionReturnContract;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticUntypedReturnContract;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StaticValue;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StdClassAll;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StdClassFactory;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StringFlag;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\StringyOnly;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\TraversableAlphaUnion;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\TripleUnion;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\TrueFlag;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\TypedUntypedTail;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\UnionAlphaBeta;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\UnionAlphaStringy;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\UnionReaderInt;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\UnionReaderString;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\VariadicByValueTail;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\VariadicShapes;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\VariadicShapesToo;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\WidePartsUnion;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\Wider;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\WideReturn;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\WriterInt;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\WriterIntToo;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\WriterString;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(TargetUnifier::class)]
#[Covers(MethodSignature::class)]
#[Covers(UnsupportedTarget::class)]
final class TargetUnifierTest
{
    private const string MATCHER = TypeRenderer::MATCHER;

    private const string ABSENT = '\\' . Absent::class;

    /** The default every required parameter of an override now carries. */
    private const string OMITTED = ' = ' . self::ABSENT . '::Argument';

    // --- Rendered signatures -------------------------------------------------

    #[DataProvider('parameterProvider')]
    public function rendersTheOverrideParameters(string $method, string $expected): void
    {
        Assert::same($this->showcase($method)->parameters, $expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function parameterProvider(): iterable
    {
        $m = self::MATCHER;
        $absent = self::ABSENT;
        $omitted = self::OMITTED;

        yield 'no parameters render an empty list' => ['noParams', ''];
        yield 'scalar gains the matcher and sentinel branches' => ['scalar', "int|{$m}|{$absent} \$a{$omitted}"];
        // The matcher goes after the contract's own branches, and `null` is
        // part of the type rather than an implicit nullable default.
        yield 'nullable expands then widens' => ['nullable', "string|null|{$m}|{$absent} \$a{$omitted}"];
        yield 'a declared default is preserved' => ['withDefault', "int|{$m} \$a = 7"];
        yield 'a null default renders lowercase' => ['withNullDefault', "int|null|{$m} \$a = null"];
        yield 'variadic carries no default' => ['variadic', "string|{$m} ...\$rest"];
        yield 'variadic follows a fixed parameter' => [
            'scalarThenVariadic',
            "int|{$m}|{$absent} \$first{$omitted}, string|{$m} ...\$rest",
        ];
        yield 'by-reference keeps its ampersand' => ['byReference', "array|{$m}|{$absent} &\$slot{$omitted}"];
        // Nothing to union onto: an untyped parameter already accepts a matcher
        // — and the sentinel default, which every required parameter carries.
        yield 'untyped stays untyped' => ['untyped', '$a' . $omitted];
        yield 'intersection is parenthesised for DNF' => [
            'intersection',
            '(\\' . ReaderInt::class . '&\\' . ReaderStringy::class . ")|{$m}|{$absent} \$a{$omitted}",
        ];
        yield 'mixed is not widened' => ['anything', 'mixed $a' . $omitted];
        yield 'object is not widened' => ['anyObject', 'object $a' . $omitted];
    }

    #[DataProvider('argumentProvider')]
    public function collectsArgumentsByName(string $method, string $expected): void
    {
        // func_get_args() would omit an argument left at its default, so
        // `withDefault()` and `withDefault(7)` would log as different calls.
        Assert::same($this->showcase($method)->arguments, $expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function argumentProvider(): iterable
    {
        yield 'no parameters' => ['noParams', '[]'];
        yield 'one parameter' => ['scalar', '[$a]'];
        yield 'defaulted parameter is still collected' => ['withDefault', '[$a]'];
        yield 'variadic is spread' => ['variadic', '[...$rest]'];
        yield 'fixed then variadic' => ['scalarThenVariadic', '[$first, ...$rest]'];
        yield 'by-reference parameter' => ['byReference', '[&$slot]'];
    }

    #[DataProvider('returnProvider')]
    public function recordsTheReturnShape(
        string $method,
        string $returnType,
        bool $never,
        bool $void,
        bool $reference,
    ): void {
        $signature = $this->showcase($method);

        Assert::same($signature->returnType, $returnType);
        Assert::same($signature->returnsNever, $never);
        Assert::same($signature->returnsVoid, $void);
        Assert::same($signature->returnsReference, $reference);
    }

    /**
     * @return iterable<string, array{string, string, bool, bool, bool}>
     */
    public static function returnProvider(): iterable
    {
        yield 'value' => ['noParams', 'int', false, false, false];
        yield 'void' => ['scalar', 'void', false, true, false];
        yield 'never' => ['goesAway', 'never', true, false, false];
        yield 'by reference' => ['returnsReference', 'array', false, false, true];
    }

    public function methodNameIsCarriedThrough(): void
    {
        Assert::same($this->showcase('scalar')->name, 'scalar');
    }

    public function staticMethodsAreRenderedButMarkedAsStatic(): void
    {
        // A static method has no instance state for a double to stand in for.
        $signatures = TargetUnifier::unify([new \ReflectionClass(WriterInt::class)]);

        Assert::true($signatures['describe']->static);
        Assert::false($signatures['write']->static);
        Assert::true(array_key_exists('write', $signatures));
    }

    public function everyContractMethodIsUnified(): void
    {
        $signatures = TargetUnifier::unify([new \ReflectionClass(ArityOne::class)]);

        Assert::same(count($signatures), 2);
    }

    // --- Unification across several targets ----------------------------------

    public function contravariantParametersUnifyIntoAUnion(): void
    {
        // `write(int)` and `write(string)` do share an implementation —
        // `write(int|string)` — so refusing them on signature difference would
        // reject a pair PHP accepts.
        $signature = $this->unify(WriterInt::class, WriterString::class)['write'];

        Assert::same(
            $signature->parameters,
            'int|string|' . self::MATCHER . '|' . self::ABSENT . ' $chunk' . self::OMITTED,
        );
    }

    public function identicalDeclarationsUnifyToThemselves(): void
    {
        $signature = $this->unify(WriterInt::class, WriterIntToo::class)['write'];

        Assert::same(
            $signature->parameters,
            'int|' . self::MATCHER . '|' . self::ABSENT . ' $chunk' . self::OMITTED,
        );
    }

    public function mixedAbsorbsNarrowerParameterTypes(): void
    {
        $signature = $this->unify(MixedWriter::class, WriterInt::class)['write'];

        Assert::same($signature->parameters, 'mixed $chunk' . self::OMITTED);
    }

    public function thePrimaryTargetsParameterNameWins(): void
    {
        $signature = $this->unify(PrimaryNamed::class, SecondaryNamed::class)['send'];

        Assert::same(
            $signature->parameters,
            'string|' . self::MATCHER . '|' . self::ABSENT . ' $primary' . self::OMITTED,
        );
        Assert::same($signature->arguments, '[$primary]');
    }

    public function aParameterMissingFromOneTargetBecomesOptional(): void
    {
        // A caller reaching the double through ArityOne passes one argument,
        // so the second must be optional — and `null` has to join the type,
        // since an implicitly nullable parameter is deprecated.
        $signature = $this->unify(ArityOne::class, ArityTwo::class)['emit'];

        Assert::same(
            $signature->parameters,
            'int|' . self::MATCHER . '|' . self::ABSENT . ' $a' . self::OMITTED
                . ', int|' . self::MATCHER . '|null $b = null',
        );
    }

    public function aVariadicAbsorbsALongerTargetsFixedParameters(): void
    {
        // `tail(int, string...)` and `tail(int, string)` are compatible: a
        // variadic accepts any number of arguments. Rendering the second
        // target's fixed parameter after `...$rest` would be a parse error.
        $signature = self::unify(NullableParam::class, FixedArityTwo::class)['tail'];

        Assert::same(substr_count($signature->parameters, '...'), 1);
        Assert::true(str_ends_with($signature->parameters, '...$rest'));
        Assert::same($signature->arguments, '[$first, ...$rest]');
    }

    public function aVariadicTailKeepsItsByReferenceMarker(): void
    {
        $signature = self::unify(VariadicShapes::class)['byRefTail'];

        Assert::true(str_contains($signature->parameters, '&...$slots'));
        Assert::same($signature->arguments, '[...$slots]');
    }

    public function anUntypedVariadicTailStaysUntyped(): void
    {
        // Nothing to union onto: every value is already allowed.
        Assert::same(self::unify(VariadicShapes::class)['untypedTail']->parameters, '...$anything');
    }

    public function aVariadicTailUnionsEveryTargetsElementType(): void
    {
        $signature = self::unify(VariadicShapes::class, VariadicShapesToo::class)['intTail'];

        Assert::string($signature->parameters)->contains('int|string|');
        Assert::true(str_ends_with($signature->parameters, '...$numbers'));
    }

    public function aVariadicTailCarriesTheMatcherBranchOnce(): void
    {
        $signature = self::unify(VariadicShapes::class)['intTail'];

        Assert::same(substr_count($signature->parameters, self::MATCHER), 1);
    }

    public function divergentReturnTypesAreRejected(): void
    {
        // Return types are covariant: `int` and `string` share no subtype, so
        // no class can implement both. The message is asserted whole — naming
        // each target beside the type it declares is the whole value of it.
        Expect::exception(UnsupportedTarget::class)->withMessage(
            "Cannot create one understudy for these targets: method `read()` has no implementation that satisfies all of them.\n"
            . '  `' . ReaderInt::class . "::read()` declares `: int`\n"
            . '  `' . ReaderString::class . '::read()` declares `: string`',
        );

        $this->unify(ReaderInt::class, ReaderString::class);
    }

    public function aNarrowerCovariantReturnSatisfiesAllTargets(): void
    {
        $signature = $this->unify(WideReturn::class, NarrowReturn::class)['value'];

        Assert::same($signature->returnType, '\\stdClass');
    }

    public function selfAndStaticReturnsUseTheNarrowerStaticType(): void
    {
        $signature = $this->unify(SelfReturn::class, StaticReturn::class)['copy'];

        Assert::same($signature->returnType, 'static');
    }

    public function unrelatedInterfaceReturnsUnifyIntoAnIntersection(): void
    {
        $signature = $this->unify(IntersectionAlpha::class, IntersectionBeta::class)['intersected'];

        Assert::same(
            $signature->returnType,
            '\\' . IntersectionAlpha::class . '&\\' . IntersectionBeta::class,
        );
    }

    public function nullableInterfaceReturnsKeepTheirSharedNullBranch(): void
    {
        $signature = $this->unify(
            IntersectionAlpha::class,
            IntersectionBeta::class,
        )['nullableIntersection'];

        Assert::same(
            $signature->returnType,
            '(\\' . IntersectionAlpha::class . '&\\' . IntersectionBeta::class . ')|null',
        );
    }

    public function aLaterReturnConflictNamesTheActuallyIncompatiblePair(): void
    {
        Expect::exception(UnsupportedTarget::class)
            ->withMessageContaining(WideReturn::class . '::value()')
            ->withMessageContaining(IntReturn::class . '::value()');

        $this->unify(WideReturn::class, NarrowReturn::class, IntReturn::class);
    }

    public function byReferenceMismatchIsRejected(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            "Cannot create one understudy for these targets: method `feed()` has no implementation that satisfies all of them.\n"
            . '  `' . FeederByRef::class . "::feed()` takes parameter #1 by reference\n"
            . '  `' . FeederByValue::class . '::feed()` takes it by value',
        );

        $this->unify(FeederByRef::class, FeederByValue::class);
    }

    public function returnByReferenceMismatchIsRejected(): void
    {
        // Returning by reference is part of the signature too: a class cannot
        // satisfy one target that returns a reference and another that does not.
        Expect::exception(UnsupportedTarget::class)->withMessage(
            "Cannot create one understudy for these targets: method `slots()` has no implementation that satisfies all of them.\n"
            . '  `' . SlotsByRef::class . "::slots()` returns by reference\n"
            . '  `' . SlotsByValue::class . '::slots()` returns by value',
        );

        $this->unify(SlotsByRef::class, SlotsByValue::class);
    }

    public function conflictsAreReportedPerMethodNotPerTargetPair(): void
    {
        // The first incompatible method aborts generation: a partially
        // generated class would be worse than a clear refusal.
        Expect::exception(UnsupportedTarget::class)->withMessageContaining('read()');

        $this->unify(ReaderInt::class, ReaderString::class, FeederByRef::class);
    }

    // --- Return-type compatibility rules -------------------------------------

    public function aNullableReturnKeepsTheShorthandReflectionReports(): void
    {
        // The compatible-candidate path renders the declaration as it stands;
        // falling through to the intersection fallback would spell the same
        // type `string|null`, which is legal but not what the contract says.
        Assert::same($this->unify(NullableString::class)['fetch']->returnType, '?string');
    }

    public function aNeverReturnSatisfiesEveryOtherTarget(): void
    {
        // `never` is a subtype of everything: a method that cannot return
        // satisfies any declared return type.
        $signature = $this->unify(NeverValue::class, IntReturn::class)['value'];

        Assert::same($signature->returnType, 'never');
        Assert::true($signature->returnsNever);
    }

    public function mixedIsSatisfiedByAnyNarrowerReturn(): void
    {
        Assert::same($this->unify(IntReturn::class, MixedValue::class)['value']->returnType, 'int');
    }

    public function objectIsSatisfiedByAnInterfaceReturn(): void
    {
        Assert::same(
            $this->unify(WideReturn::class, InterfaceValue::class)['value']->returnType,
            '\\' . ReaderInt::class,
        );
    }

    public function objectIsSatisfiedByASelfReturn(): void
    {
        // Either rendering satisfies both contracts, and which one Reflection
        // reports is a PHP-version difference: 8.3/8.4 keep `self`, 8.5
        // resolves it to the declaring interface.
        Assert::true(in_array(
            $this->unify(WideReturn::class, SelfValue::class)['value']->returnType,
            ['self', '\\' . SelfValue::class],
            strict: true,
        ));
    }

    public function objectIsSatisfiedByAStaticReturn(): void
    {
        Assert::same($this->unify(WideReturn::class, StaticValue::class)['value']->returnType, 'static');
    }

    public function twoObjectReturnsStayObject(): void
    {
        Assert::same($this->unify(WideReturn::class, ObjectValueToo::class)['value']->returnType, 'object');
    }

    public function boolIsSatisfiedByTheTrueLiteral(): void
    {
        Assert::same($this->unify(BoolFlag::class, TrueFlag::class)['flag']->returnType, 'true');
    }

    public function iterableIsSatisfiedByArray(): void
    {
        Assert::same($this->unify(IterableAll::class, ArrayAll::class)['all']->returnType, 'array');
    }

    public function iterableIsSatisfiedByATraversableClass(): void
    {
        Assert::same($this->unify(IterableAll::class, GeneratorAll::class)['all']->returnType, '\\Generator');
    }

    public function callableIsSatisfiedByClosure(): void
    {
        Assert::same($this->unify(CallableFactory::class, ClosureFactory::class)['factory']->returnType, '\\Closure');
    }

    public function aClassReturnSatisfiesAnInterfaceItImplements(): void
    {
        // The class-to-class rule is the last one tried, and it is the only
        // one that has to strip the leading backslash before asking is_a().
        Assert::same(
            $this->unify(CountableValue::class, ArrayObjectValue::class)['value']->returnType,
            '\\ArrayObject',
        );
    }

    public function aBuiltinReturnNeverSatisfiesAClassReturn(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            "Cannot create one understudy for these targets: method `value()` has no implementation that satisfies all of them.\n"
            . '  `' . CountableValue::class . "::value()` declares `: \\Countable`\n"
            . '  `' . IntReturn::class . '::value()` declares `: int`',
        );

        $this->unify(CountableValue::class, IntReturn::class);
    }

    public function unionReturnsCollapseToTheBranchTheyShare(): void
    {
        // Neither union satisfies the other, but they overlap in one branch —
        // that overlap is the only return type a class could declare.
        Assert::same(
            $this->unify(UnionReaderInt::class, UnionReaderString::class)['value']->returnType,
            '\\' . ReaderInt::class,
        );
    }

    public function aStaticAndAnInstanceMethodOfTheSameNameCannotBeUnified(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            "Cannot create one understudy for these targets: method `describe()` has no implementation that satisfies all of them.\n"
            . '  `' . WriterInt::class . "::describe()` is static\n"
            . '  `' . InstanceDescribe::class . '::describe()` is an instance method',
        );

        $this->unify(WriterInt::class, InstanceDescribe::class);
    }

    // --- Class statics against an interface contract -------------------------

    /**
     * The generated class inherits the class static, so the whole declaration
     * has to satisfy the interface — PHP rejects the class outright otherwise,
     * and a fatal error is not something a test can catch.
     */
    public function aWiderStaticParameterStillSatisfiesTheContract(): void
    {
        Assert::false(
            array_key_exists('ping', $this->unify(StaticPingWiderParameter::class, StaticPingContract::class)),
        );
    }

    public function extraOptionalStaticParametersStillSatisfyTheContract(): void
    {
        Assert::false(
            array_key_exists('ping', $this->unify(StaticPingOptional::class, StaticPingContract::class)),
        );
    }

    public function aStaticVariadicTailCoversTheContractsFixedParameters(): void
    {
        Assert::false(
            array_key_exists('ping', $this->unify(StaticPingVariadicTail::class, StaticPartsContract::class)),
        );
    }

    /**
     * The tail is the *last* declared parameter, not the first: `$first` is
     * fixed and only `...$rest` stretches to cover what the contract declares
     * beyond it.
     */
    public function aStaticVariadicTailAlignsFromTheEndOfTheDeclaration(): void
    {
        Assert::false(
            array_key_exists('ping', $this->unify(StaticPingFixedThenTail::class, StaticMixedPartsContract::class)),
        );
    }

    public function anOptionalStaticParameterCannotSatisfyAVariadicContract(): void
    {
        Expect::exception(UnsupportedTarget::class)
            ->withMessageContaining('has an incompatible static signature');

        $this->unify(StaticPingOptionalInsteadOfTail::class, StaticTailContract::class);
    }

    public function aNarrowerStaticParameterCannotSatisfyTheContract(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            "Cannot create one understudy for these targets: method `ping()` has no implementation that satisfies all of them.\n"
            . '  `' . StaticPingNarrowerParameter::class . "::ping()` has an incompatible static signature\n"
            . '  `' . StaticPingContract::class . '::ping()` requires a compatible static signature',
        );

        $this->unify(StaticPingNarrowerParameter::class, StaticPingContract::class);
    }

    /**
     * An abstract static has no implementation for the generated subclass to
     * inherit, so it has to be declared there. PHP refuses a concrete subclass
     * that leaves one unimplemented, and refuses it with a fatal error — the
     * one failure a target check exists to reach first.
     */
    public function anUnimplementedInterfaceStaticIsRedeclaredByTheDouble(): void
    {
        $signature = $this->unify(AbstractStaticFromInterface::class)['ping'] ?? null;

        Assert::instanceOf($signature, MethodSignature::class);
        Assert::true($signature->static);
    }

    public function anAbstractStaticDeclaredOnTheClassIsRedeclaredByTheDouble(): void
    {
        $signature = $this->unify(AbstractOwnStatic::class)['tag'] ?? null;

        Assert::instanceOf($signature, MethodSignature::class);
        Assert::true($signature->static);
    }

    public function aStaticReturnConflictNamesBothDeclarations(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            "Cannot create one understudy for these targets: method `ping()` has no implementation that satisfies all of them.\n"
            . '  `' . StaticPingRequiredParameter::class . "::ping()` declares `: int`\n"
            . '  `' . StaticStringReturnContract::class . '::ping()` declares `: string`',
        );

        $this->unify(StaticPingRequiredParameter::class, StaticStringReturnContract::class);
    }

    public function aClassStaticCannotStandInForAnInstanceMethod(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            "Cannot create one understudy for these targets: method `ping()` has no implementation that satisfies all of them.\n"
            . '  `' . StaticPingRequiredParameter::class . "::ping()` is static\n"
            . '  `' . StaticPingAsInstanceContract::class . '::ping()` is an instance method',
        );

        $this->unify(StaticPingRequiredParameter::class, StaticPingAsInstanceContract::class);
    }

    public function aProtectedStaticCannotSatisfyAPublicContract(): void
    {
        Expect::exception(UnsupportedTarget::class)
            ->withMessageContaining('has an incompatible static signature');

        $this->unify(StaticPingProtected::class, StaticPingContract::class);
    }

    public function aStaticThatDemandsMoreParametersCannotSatisfyTheContract(): void
    {
        Expect::exception(UnsupportedTarget::class)
            ->withMessageContaining('has an incompatible static signature');

        $this->unify(StaticPingExtraRequired::class, StaticPingContract::class);
    }

    public function aStaticThatDeclaresFewerParametersCannotSatisfyTheContract(): void
    {
        Expect::exception(UnsupportedTarget::class)
            ->withMessageContaining('has an incompatible static signature');

        $this->unify(StaticPingTooFewParameters::class, StaticPingContract::class);
    }

    public function aFixedStaticParameterCannotSatisfyAVariadicContract(): void
    {
        Expect::exception(UnsupportedTarget::class)
            ->withMessageContaining('has an incompatible static signature');

        $this->unify(StaticPingFixedInsteadOfTail::class, StaticTailContract::class);
    }

    public function aByValueStaticParameterCannotSatisfyAByReferenceContract(): void
    {
        Expect::exception(UnsupportedTarget::class)
            ->withMessageContaining('has an incompatible static signature');

        $this->unify(StaticPingByValueSlot::class, StaticSlotContract::class);
    }

    public function aRequiredStaticParameterCannotSatisfyAnOptionalContract(): void
    {
        Expect::exception(UnsupportedTarget::class)
            ->withMessageContaining('has an incompatible static signature');

        $this->unify(StaticPingRequiredParameter::class, StaticOptionalContract::class);
    }

    public function aByValueStaticReturnCannotSatisfyAByReferenceContract(): void
    {
        Expect::exception(UnsupportedTarget::class)
            ->withMessageContaining('has an incompatible static signature');

        $this->unify(StaticPingByValueReturn::class, StaticReferenceReturnContract::class);
    }

    // --- Parameter rendering -------------------------------------------------

    public function anObjectVariadicTailIsNotWidened(): void
    {
        // `object` already accepts a matcher instance, so a matcher branch
        // would only make the rendered type redundant.
        Assert::same($this->unify(ObjectTail::class)['sink']->parameters, 'object ...$items');
    }

    public function anObjectTailStaysUnwidenedWhenAnotherTargetIsTyped(): void
    {
        Assert::same(
            $this->unify(ObjectTail::class, IntTailPlain::class)['sink']->parameters,
            'object|int ...$items',
        );
    }

    public function aDnfParameterSplitsOnTheTopLevelUnionOnly(): void
    {
        // `(A&B)|null` is two branches, not four fragments: splitting inside
        // the parentheses would emit `(A` and `B)` as separate types.
        $signature = $this->unify(DnfParam::class, DnfParamToo::class)['store'];

        Assert::same(
            $signature->parameters,
            '(\\' . ReaderInt::class . '&\\' . ReaderStringy::class . ')|null|'
                . self::MATCHER . '|' . self::ABSENT . ' $slot' . self::OMITTED,
        );
    }

    public function aVariadicTailByReferenceInOneTargetOnlyIsRejected(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            "Cannot create one understudy for these targets: method `byRefTail()` has no implementation that satisfies all of them.\n"
            . '  `' . VariadicShapes::class . "::byRefTail()` takes its variadic tail by reference\n"
            . '  `' . VariadicByValueTail::class . '::byRefTail()` takes it by value',
        );

        $this->unify(VariadicShapes::class, VariadicByValueTail::class);
    }

    public function theTrueLiteralDoesNotSatisfyAnUnrelatedReturn(): void
    {
        // `true` is a subtype of `bool` only: naming it alone would let any
        // scalar contract through.
        Expect::exception(UnsupportedTarget::class)->withMessage(
            "Cannot create one understudy for these targets: method `flag()` has no implementation that satisfies all of them.\n"
            . '  `' . TrueFlag::class . "::flag()` declares `: true`\n"
            . '  `' . StringFlag::class . '::flag()` declares `: string`',
        );

        $this->unify(TrueFlag::class, StringFlag::class);
    }

    public function iterableIsNotSatisfiedByANonTraversableClass(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            "Cannot create one understudy for these targets: method `all()` has no implementation that satisfies all of them.\n"
            . '  `' . IterableAll::class . "::all()` declares `: iterable`\n"
            . '  `' . StdClassAll::class . '::all()` declares `: \stdClass`',
        );

        $this->unify(IterableAll::class, StdClassAll::class);
    }

    public function callableIsNotSatisfiedByAnArbitraryObject(): void
    {
        Expect::exception(UnsupportedTarget::class)->withMessage(
            "Cannot create one understudy for these targets: method `factory()` has no implementation that satisfies all of them.\n"
            . '  `' . CallableFactory::class . "::factory()` declares `: callable`\n"
            . '  `' . StdClassFactory::class . '::factory()` declares `: \stdClass`',
        );

        $this->unify(CallableFactory::class, StdClassFactory::class);
    }

    public function unionsThatOverlapPartiallyRenderAsDnf(): void
    {
        // (A|B) and (A|S) share A outright, and their remaining branches can
        // only be satisfied together — `A|(B&S)` is exactly that.
        $signature = $this->unify(UnionAlphaBeta::class, UnionAlphaStringy::class)['pick'];

        Assert::same(
            $signature->returnType,
            '\\' . IntersectionAlpha::class
            . '|(\\' . IntersectionBeta::class . '&\\' . ReaderStringy::class . ')',
        );
    }

    public function aUnionBranchNarrowerThanTheOtherTargetsWins(): void
    {
        // `\ArrayObject` implements `\Countable`, so it is the only branch a
        // class could declare for both targets.
        $signatures = $this->unify(ClassUnionFirst::class, InterfaceUnionSecond::class);

        Assert::same($signatures['narrow']->returnType, '\ArrayObject');
        Assert::same($signatures['wide']->returnType, '\ArrayObject');
    }

    public function aThreeBranchUnionKeepsTheBranchTheOtherTargetNeeds(): void
    {
        $signature = $this->unify(TripleUnion::class, StringyOnly::class)['pick'];

        Assert::same($signature->returnType, '\\' . ReaderStringy::class);
    }

    public function theReportedConflictSkipsPairsThatDoIntersect(): void
    {
        // Alpha and Beta unify into `Alpha&Beta`; the pair worth naming is the
        // one that no class could satisfy at all.
        Expect::exception(UnsupportedTarget::class)->withMessage(
            "Cannot create one understudy for these targets: method `intersected()` has no implementation that satisfies all of them.\n"
            . '  `' . IntersectionAlpha::class . "::intersected()` declares `: \\" . IntersectionAlpha::class . "`\n"
            . '  `' . IntersectedInt::class . '::intersected()` declares `: int`',
        );

        $this->unify(IntersectionAlpha::class, IntersectionBeta::class, IntersectedInt::class);
    }

    public function anUntypedTailStaysUntypedEvenWhenAnotherTargetTypesIt(): void
    {
        // One untyped target means the union cannot be expressed: rendering
        // the other target's `int` would reject calls the contract allows.
        $signature = $this->unify(VariadicShapes::class, TypedUntypedTail::class)['untypedTail'];

        Assert::same($signature->parameters, '...$anything');
    }

    public function aMixedTailAbsorbsEveryOtherTargetsType(): void
    {
        Assert::same($this->unify(MixedTail::class, IntTailPlain::class)['sink']->parameters, 'mixed ...$items');
    }

    public function theReportedConflictIsNotSimplyTheFirstAndLastTarget(): void
    {
        // Alpha and Beta do intersect; the pair that cannot be satisfied sits
        // in the middle of the list, and naming the outer two would mislead.
        Expect::exception(UnsupportedTarget::class)->withMessage(
            "Cannot create one understudy for these targets: method `intersected()` has no implementation that satisfies all of them.\n"
            . '  `' . IntersectionAlpha::class . "::intersected()` declares `: \\" . IntersectionAlpha::class . "`\n"
            . '  `' . IntersectedInt::class . '::intersected()` declares `: int`',
        );

        $this->unify(IntersectionAlpha::class, IntersectedInt::class, IntersectionBeta::class);
    }

    public function anIntersectionReturnKeepsEveryAtomWhenItIsNarrowedFurther(): void
    {
        $signature = $this->unify(IntersectedPair::class, StringyOnly::class)['pick'];

        Assert::same(
            $signature->returnType,
            '\\' . IntersectionAlpha::class
            . '&\\' . IntersectionBeta::class
            . '&\\' . ReaderStringy::class,
        );
    }

    public function anIntersectionDropsTheAtomAnotherAtomAlreadyImplies(): void
    {
        // `\IteratorAggregate` already is a `\Traversable`: keeping both would
        // render an intersection PHP rejects as redundant.
        $signature = $this->unify(TraversableAlphaUnion::class, AggregateUnion::class)['pick'];

        Assert::same(
            $signature->returnType,
            '\\' . IntersectionAlpha::class . '&\IteratorAggregate',
        );
    }

    /**
     * The narrower atom arrives second, so the loop has to drop the one already
     * collected rather than only skip the newcomer — `Narrower&Wider` is the
     * redundant intersection PHP rejects.
     */
    public function anIntersectionDropsAnAtomTheNewcomerAlreadyImplies(): void
    {
        $signature = $this->unify(Wider::class, Narrower::class)['shape'];

        Assert::same($signature->returnType, '\\' . Narrower::class);
    }

    /**
     * The same pair the other way round: the newcomer is the wider one, so it
     * is the newcomer that is redundant.
     */
    public function anIntersectionDropsARedundantNewcomer(): void
    {
        $signature = $this->unify(Narrower::class, Wider::class)['shape'];

        Assert::same($signature->returnType, '\\' . Narrower::class);
    }

    /**
     * Neither implies the other, so both survive and the double must satisfy
     * both at once.
     */
    public function twoUnrelatedAtomsBothSurvive(): void
    {
        $signature = $this->unify(Wider::class, Sibling::class)['shape'];

        Assert::same($signature->returnType, '\\' . Wider::class . '&\\' . Sibling::class);
    }

    public function aMiddleUnionBranchIsKeptLikeAnyOther(): void
    {
        $signature = $this->unify(TripleUnion::class, BetaOnly::class)['pick'];

        Assert::same($signature->returnType, '\\' . IntersectionBeta::class);
    }

    public function anIntersectionKeepsEveryAtomWhenTheNarrowingSideComesSecond(): void
    {
        // Same pair as above with the targets swapped: the atoms of the second
        // target must survive too, not just its first one.
        $signature = $this->unify(StringyOnly::class, IntersectedPair::class)['pick'];

        Assert::same(
            $signature->returnType,
            '\\' . ReaderStringy::class
            . '&\\' . IntersectionAlpha::class
            . '&\\' . IntersectionBeta::class,
        );
    }

    public function oneAtomCanSupersedeSeveralAlreadyCollected(): void
    {
        // `BothIterableCountable` implies `\Traversable` *and* `\Countable`;
        // stopping after the first of them would leave a redundant atom.
        $signature = $this->unify(WidePartsUnion::class, NarrowPartsUnion::class)['pick'];

        Assert::same(
            $signature->returnType,
            '\Stringable&\\' . BothIterableCountable::class . '&\JsonSerializable',
        );
    }

    // --- Constant defaults render as their declared form ---------------------

    /**
     * The value alone cannot tell `= \\Cfg::LIMIT` from `= 5`, so the rendered
     * SOURCE is asserted: a constant default keeps its name, resolved through
     * the class that declares it — `SELF`/`PARENT` (case included) never
     * follow the double into a class that never had the constant.
     */
    #[DataProvider('constantDefaultProvider')]
    public function rendersAConstantDefaultByItsDeclaredName(string $method, string $expected): void
    {
        $signature = TargetUnifier::unify([new \ReflectionClass(ConstantDefaults::class)])[$method];

        Assert::same($signature->parameters, $expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function constantDefaultProvider(): iterable
    {
        $m = self::MATCHER;

        yield "another class's constant" => [
            'viaClass',
            "int|{$m} \$a = \\" . KnownConstants::class . '::LIMIT',
        ];
        yield 'SELF resolves to the declaring class, whatever the case' => [
            'viaSelfUpper',
            "int|{$m} \$a = \\" . ConstantDefaults::class . '::MINE',
        ];
        yield 'PARENT resolves to the parent class, not the declaring one' => [
            'viaParentUpper',
            "int|{$m} \$a = \\" . ConstantDefaultBase::class . '::FROM_PARENT',
        ];
        yield 'an interface constant' => [
            'viaInterfaceConstant',
            "string|{$m} \$a = \\" . ConstantsInterface::class . '::MODE',
        ];
    }

    // --- Reference detection -------------------------------------------------

    /**
     * `hasReferenceParameters` gates the by-reference snapshots, so it must
     * fold across every position — a `&` anywhere makes it true, none makes
     * it false, whichever side of the parameter list it sits on.
     */
    public function referenceDetectionFoldsAcrossEveryPosition(): void
    {
        Assert::false($this->showcase('scalar')->hasReferenceParameters);
        Assert::true($this->showcase('byReference')->hasReferenceParameters);
        Assert::true($this->showcase('refFirst')->hasReferenceParameters);
        Assert::true($this->showcase('refSecond')->hasReferenceParameters);
    }

    // --- Static satisfaction: the variadic tail and the return branches ------

    /**
     * The satisfied complement of the misalignment tests above: the tail is
     * the LAST declared parameter, and it is that parameter — not the first —
     * that stretches over the contract's remaining ones. `$first` is `int`
     * and the tail is `string` precisely so that reading the wrong end is a
     * type mismatch rather than a coincidence.
     */
    public function aStaticVariadicTailSatisfiesTypeDistinctRemainingParameters(): void
    {
        Assert::false(
            array_key_exists('ping', $this->unify(StaticPingIntThenTail::class, StaticIntPartsContract::class)),
        );
    }

    public function aTypedStaticImplementationSatisfiesAnUntypedContract(): void
    {
        Assert::false(
            array_key_exists('ping', $this->unify(StaticPingTypedReturn::class, StaticUntypedReturnContract::class)),
        );
    }

    public function aStaticBranchOfAUnionReturnSatisfiesTheContract(): void
    {
        Assert::false(
            array_key_exists('ping', $this->unify(StaticPingTypedReturn::class, StaticUnionReturnContract::class)),
        );
    }

    // --- Untyped returns unify with typed ones -------------------------------

    /**
     * A declaration with no return type allows anything, so the typed
     * declaration is the one every target satisfies — the unified return is
     * `int`, not a refusal and not `mixed`.
     */
    public function anUntypedReturnIsAbsorbedByTheTypedOne(): void
    {
        Assert::same($this->unify(ReaderUntyped::class, ReaderInt::class)['read']->returnType, 'int');
    }

    /**
     * @return array<non-empty-string, MethodSignature>
     */
    private function unify(string ...$contracts): array
    {
        return TargetUnifier::unify(array_map(
            static fn(string $contract): \ReflectionClass => new \ReflectionClass($contract),
            array_values($contracts),
        ));
    }

    private function showcase(string $method): MethodSignature
    {
        $signature = TargetUnifier::unify([new \ReflectionClass(Showcase::class)])[$method] ?? null;

        Assert::instanceOf($signature, MethodSignature::class);

        return $signature;
    }
}
