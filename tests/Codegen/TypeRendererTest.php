<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Codegen;

use Rasuvaeff\Understudy\Codegen\TypeRenderer;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ReaderInt;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\ReaderStringy;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\SelfParameter;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\SelfParameterTraitUser;
use Rasuvaeff\Understudy\Tests\Fixture\Unify\TypeShowcase;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(TypeRenderer::class)]
final class TypeRendererTest
{
    #[DataProvider('parameterProvider')]
    public function rendersTheContractsOwnParameterType(string $method, string $expected): void
    {
        Assert::same(TypeRenderer::parameterType($this->typeOf($method)), $expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function parameterProvider(): iterable
    {
        yield 'scalar' => ['scalar', 'int'];
        // `?int` is expanded so that a matcher branch can be appended to it.
        yield 'nullable expands to a union' => ['nullable', 'int|null'];
        yield 'mixed' => ['anything', 'mixed'];
        yield 'object' => ['anyObject', 'object'];
        yield 'untyped renders empty' => ['untyped', ''];
    }

    public function unionKeepsEveryBranch(): void
    {
        // Reflection reports its own canonical order, so assert the set.
        $rendered = TypeRenderer::parameterType($this->typeOf('union'));

        Assert::same(count(explode('|', $rendered)), 2);
        Assert::string($rendered)->contains('int');
        Assert::string($rendered)->contains('string');
    }

    public function intersectionIsParenthesisedForDnf(): void
    {
        // A union may only contain an intersection in parentheses, and a
        // matcher branch will be appended to exactly this rendering.
        $rendered = TypeRenderer::parameterType($this->typeOf('intersection'));

        Assert::true(str_starts_with($rendered, '('));
        Assert::true(str_ends_with($rendered, ')'));
        Assert::string($rendered)->contains('&');
    }

    public function classTypesAreFullyQualified(): void
    {
        Assert::true(str_starts_with(TypeRenderer::parameterType($this->typeOf('objectParam')), '\\'));
    }

    public function aNullableClassKeepsItsLeadingBackslash(): void
    {
        // Generated code lives in its own namespace, so `?Book` must expand to
        // `\Book|null`: a relative name would resolve to a class that does not
        // exist, and only at call time.
        Assert::same(
            TypeRenderer::parameterType($this->typeOf('nullableObject')),
            '\\' . TypeShowcase::class . '|null',
        );
    }

    #[DataProvider('matcherProvider')]
    public function reportsWhichTypesAlreadyAcceptAMatcher(string $method, bool $expected): void
    {
        Assert::same(TypeRenderer::acceptsMatcher($this->typeOf($method)), $expected);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function matcherProvider(): iterable
    {
        yield 'mixed admits any object' => ['anything', true];
        yield 'object admits any object' => ['anyObject', true];
        yield 'int does not' => ['scalar', false];
        yield 'intersection does not' => ['intersection', false];
        yield 'untyped is not a declared type' => ['untyped', false];
    }

    #[DataProvider('returnProvider')]
    public function rendersReturnTypes(string $method, string $expected): void
    {
        Assert::same(
            TypeRenderer::returnType((new \ReflectionMethod(TypeShowcase::class, $method))->getReturnType()),
            $expected,
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function returnProvider(): iterable
    {
        yield 'void' => ['scalar', 'void'];
        yield 'nullable class keeps its shorthand' => ['returnsNullableObject', '?\\' . TypeShowcase::class];
        yield 'never' => ['returnsNever', 'never'];
    }

    public function anAbsentReturnTypeBecomesMixed(): void
    {
        Assert::same(TypeRenderer::returnType(null), 'mixed');
    }


    public function aDnfParameterKeepsItsParenthesesInsideTheUnion(): void
    {
        // Only the intersection is parenthesised, and every branch is
        // rendered — a union of one branch would be a different type.
        Assert::same(
            TypeRenderer::parameterType($this->typeOf('dnf')),
            '(\\' . ReaderInt::class . '&\\' . ReaderStringy::class . ')|null',
        );
    }

    public function aDnfReturnTypeIsRenderedTheSameWay(): void
    {
        Assert::same(
            TypeRenderer::returnType($this->returnTypeOf('returnsDnf')),
            '(\\' . ReaderInt::class . '&\\' . ReaderStringy::class . ')|null',
        );
    }

    public function anIntersectionRendersEveryAtom(): void
    {
        Assert::same(
            TypeRenderer::parameterType($this->typeOf('intersection')),
            '(\\' . ReaderInt::class . '&\\' . ReaderStringy::class . ')',
        );
    }

    #[DataProvider('selfParameterProvider')]
    public function aSelfParameterIsRenderedAsTheDeclaringClass(string $class, string $method, string $expected): void
    {
        $parameter = (new \ReflectionMethod($class, $method))->getParameters()[0];

        Assert::same(
            TypeRenderer::parameterType($parameter->getType(), $parameter->getDeclaringClass()),
            $expected,
        );
    }

    /**
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function selfParameterProvider(): iterable
    {
        yield 'plain' => [SelfParameter::class, 'mergeWith', '\\' . SelfParameter::class];
        yield 'nullable' => [SelfParameter::class, 'mergeMaybe', '\\' . SelfParameter::class . '|null'];
        yield 'from a trait' => [SelfParameterTraitUser::class, 'mergeWith', '\\' . SelfParameterTraitUser::class];
    }

    public function theSelfKeywordIsMatchedInAnyCase(): void
    {
        // Keywords are case-insensitive and Reflection keeps the spelling, so
        // `SELF` reaches here verbatim — on 8.3 and 8.4 from anywhere, and on
        // 8.5 from a trait, which is why the method lives in one. Declared
        // through eval because the code style fixer lowercases it in a file.
        if (!class_exists('Rasuvaeff\\Understudy\\Tests\\Codegen\\UpperSelfParameter', autoload: false)) {
            eval('namespace Rasuvaeff\\Understudy\\Tests\\Codegen; trait UpperSelfTrait { public function merge(SELF $other): SELF { return $this; } } class UpperSelfParameter { use UpperSelfTrait; }');
        }

        $parameter = (new \ReflectionMethod('Rasuvaeff\\Understudy\\Tests\\Codegen\\UpperSelfParameter', 'merge'))->getParameters()[0];

        Assert::same(
            TypeRenderer::parameterType($parameter->getType(), $parameter->getDeclaringClass()),
            '\\Rasuvaeff\\Understudy\\Tests\\Codegen\\UpperSelfParameter',
        );
        // In a return the keyword stays a keyword where Reflection reports one
        // (8.3, 8.4) and is the class where it resolves it (8.5) — never
        // `\\SELF`, a class name PHP refuses outright.
        $return = TypeRenderer::returnType(
            (new \ReflectionMethod('Rasuvaeff\\Understudy\\Tests\\Codegen\\UpperSelfParameter', 'merge'))->getReturnType(),
        );

        Assert::true(\in_array($return, ['SELF', '\\Rasuvaeff\\Understudy\\Tests\\Codegen\\UpperSelfParameter'], strict: true));
    }

    public function aSelfParameterWithoutADeclaringClassIsLeftAlone(): void
    {
        $type = (new \ReflectionMethod(SelfParameterTraitUser::class, 'mergeWith'))->getParameters()[0]->getType();

        Assert::same(TypeRenderer::parameterType($type), 'self');
    }

    private function typeOf(string $method): ?\ReflectionType
    {
        return (new \ReflectionMethod(TypeShowcase::class, $method))->getParameters()[0]->getType();
    }

    private function returnTypeOf(string $method): ?\ReflectionType
    {
        return (new \ReflectionMethod(TypeShowcase::class, $method))->getReturnType();
    }
}
