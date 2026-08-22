<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Codegen;

use Rasuvaeff\Understudy\Codegen\MethodSignature;
use Rasuvaeff\Understudy\Codegen\TargetUnifier;
use Rasuvaeff\Understudy\Codegen\TypeRenderer;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ArityOne;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ArityTwo;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\FeederByRef;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\FeederByValue;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\FixedArityTwo;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\NullableParam;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ReaderInt;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ReaderString;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ReaderStringy;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\Showcase;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\SlotsByRef;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\SlotsByValue;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\VariadicShapes;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\VariadicShapesToo;
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
final class TargetUnifierTest
{
    private const string MATCHER = TypeRenderer::MATCHER;

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

        yield 'no parameters render an empty list' => ['noParams', ''];
        yield 'scalar gains the matcher branch' => ['scalar', "int|{$m} \$a"];
        // The matcher goes after the contract's own branches, and `null` is
        // part of the type rather than an implicit nullable default.
        yield 'nullable expands then widens' => ['nullable', "string|null|{$m} \$a"];
        yield 'a declared default is preserved' => ['withDefault', "int|{$m} \$a = 7"];
        yield 'a null default renders lowercase' => ['withNullDefault', "int|null|{$m} \$a = null"];
        yield 'variadic carries no default' => ['variadic', "string|{$m} ...\$rest"];
        yield 'variadic follows a fixed parameter' => [
            'scalarThenVariadic',
            "int|{$m} \$first, string|{$m} ...\$rest",
        ];
        yield 'by-reference keeps its ampersand' => ['byReference', "array|{$m} &\$slot"];
        // Nothing to union onto: an untyped parameter already accepts a matcher.
        yield 'untyped stays untyped' => ['untyped', '$a'];
        yield 'intersection is parenthesised for DNF' => [
            'intersection',
            '(\\' . ReaderInt::class . '&\\' . ReaderStringy::class . ")|{$m} \$a",
        ];
        yield 'mixed is not widened' => ['anything', 'mixed $a'];
        yield 'object is not widened' => ['anyObject', 'object $a'];
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
        yield 'by-reference parameter' => ['byReference', '[$slot]'];
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

    public function staticMethodsAreNotIntercepted(): void
    {
        // A static method has no instance state for a double to stand in for.
        $signatures = TargetUnifier::unify([new \ReflectionClass(WriterInt::class)]);

        Assert::false(array_key_exists('describe', $signatures));
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

        Assert::same($signature->parameters, 'int|string|' . self::MATCHER . ' $chunk');
    }

    public function identicalDeclarationsUnifyToThemselves(): void
    {
        $signature = $this->unify(WriterInt::class, WriterIntToo::class)['write'];

        Assert::same($signature->parameters, 'int|' . self::MATCHER . ' $chunk');
    }

    public function aParameterMissingFromOneTargetBecomesOptional(): void
    {
        // A caller reaching the double through ArityOne passes one argument,
        // so the second must be optional — and `null` has to join the type,
        // since an implicitly nullable parameter is deprecated.
        $signature = $this->unify(ArityOne::class, ArityTwo::class)['emit'];

        Assert::same(
            $signature->parameters,
            'int|' . self::MATCHER . ' $a, int|' . self::MATCHER . '|null $b = null',
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
