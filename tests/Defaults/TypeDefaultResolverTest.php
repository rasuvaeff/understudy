<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Defaults;

use Rasuvaeff\Understudy\Codegen\MethodSignature;
use Rasuvaeff\Understudy\Defaults\TypeDefaultResolver;
use Rasuvaeff\Understudy\Exception\NoDefaultValue;
use Rasuvaeff\Understudy\Runtime\RuntimeContext;
use Rasuvaeff\Understudy\Tests\Fixture\FluentBuilder;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(TypeDefaultResolver::class)]
#[Covers(MethodSignature::class)]
#[Covers(NoDefaultValue::class)]
final class TypeDefaultResolverTest
{
    #[DataProvider('safeDefaultProvider')]
    public function returnsATypeSafeDefault(string $returnType, mixed $expected): void
    {
        Assert::same(TypeDefaultResolver::forSignature('Contract', $this->signature($returnType), 'method', new RuntimeContext()), $expected);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function safeDefaultProvider(): iterable
    {
        yield 'void' => ['void', null];
        yield 'mixed' => ['mixed', null];
        yield 'null' => ['null', null];
        yield 'nullable shorthand' => ['?string', null];
        yield 'bool' => ['bool', false];
        yield 'false' => ['false', false];
        yield 'true' => ['true', true];
        yield 'int' => ['int', 0];
        yield 'float' => ['float', 0.0];
        yield 'string' => ['string', ''];
        yield 'array' => ['array', []];
        yield 'iterable' => ['iterable', []];
        yield 'union containing null' => ['string|null', null];
        yield 'union without null takes the first branch' => ['string|int', ''];
    }

    public function objectDefaultIsAnEmptyStdClass(): void
    {
        $value = TypeDefaultResolver::forSignature('Contract', $this->signature('object'), 'method', new RuntimeContext());

        Assert::instanceOf($value, \stdClass::class);
    }

    public function generatorDefaultIsAnEmptyGeneratorNotAnArray(): void
    {
        // `[]` would violate a declared `: Generator`.
        $value = TypeDefaultResolver::forSignature('Contract', $this->signature('Generator'), 'method', new RuntimeContext());

        Assert::instanceOf($value, \Generator::class);
        Assert::same(iterator_to_array($value), []);
    }

    public function traversableDefaultIsAnEmptyIterator(): void
    {
        $value = TypeDefaultResolver::forSignature('Contract', $this->signature('Traversable'), 'method', new RuntimeContext());

        Assert::instanceOf($value, \EmptyIterator::class);
    }

    public function callableDefaultIsInvokable(): void
    {
        $value = TypeDefaultResolver::forSignature('Contract', $this->signature('callable'), 'method', new RuntimeContext());

        Assert::true(is_callable($value));
    }

    public function aUnionFallsBackToTheFirstBranchWithASafeDefault(): void
    {
        // `Book|string` must answer `''` rather than failing on `Book`.
        Assert::same(
            TypeDefaultResolver::forSignature('Contract', $this->signature('\\DateTimeImmutable|string'), 'method', new RuntimeContext()),
            '',
        );
    }

    public function aBranchIsMatchedExactlyNotAsASubstring(): void
    {
        // A class whose name merely contains "null" is not a null branch, and
        // a union of two such classes has no safe default at all.
        Expect::exception(NoDefaultValue::class);

        TypeDefaultResolver::forSignature('Contract', $this->signature('\\NullableThing|\\AnnullableThing'), 'method', new RuntimeContext());
    }

    public function anIntersectionBranchSurvivesUnionSplitting(): void
    {
        // `(A&B)|null` has two branches, not three.
        Assert::null(
            TypeDefaultResolver::forSignature('Contract', $this->signature('(\\A&\\B)|null'), 'method', new RuntimeContext()),
        );
    }

    /**
     * `: static` names the receiver, and the receiver is the double — so the
     * double is a valid answer and no invention is needed. It used to refuse,
     * which made a fluent contract unusable without a stub for every link of
     * the chain.
     */
    public function staticAnswersWithTheDoubleItself(): void
    {
        $double = new \stdClass();

        Assert::same(
            TypeDefaultResolver::forSignature(
                'Contract',
                $this->signature('static'),
                'method',
                new RuntimeContext(),
                nested: false,
                double: $double,
            ),
            $double,
        );
    }

    /**
     * With no double in hand there is nothing `static` could mean — a hooked
     * property's type resolves through this same table, and refusing is what
     * the whole table does when it has no safe answer.
     */
    public function staticWithoutADoubleStillHasNoSafeDefault(): void
    {
        Expect::exception(NoDefaultValue::class);

        TypeDefaultResolver::forSignature('Contract', $this->signature('static'), 'method', new RuntimeContext());
    }

    /**
     * The same claim through a real double, because the resolver is only half
     * of it: the generated method has to declare `static` for the answer to
     * satisfy PHP's own return check, and a chain has to keep working past
     * the first link.
     */
    public function aFluentContractChainsWithoutAStubPerLink(): void
    {
        $double = Understudy::for(FluentBuilder::class);

        Assert::same($double->with(1)->with(2)->with(3), $double);
        Assert::same($double->build(), '');

        Understudy::reset();
    }

    public function anUnknownSignatureAnswersWithNull(): void
    {
        Assert::null(TypeDefaultResolver::forSignature('Contract', null, 'method', new RuntimeContext()));
    }

    public function anArbitraryClassHasNoSafeDefault(): void
    {
        // Running someone else's constructor to invent a value, or handing back
        // an object whose constructor was skipped, are both worse than saying
        // there is nothing safe to return.
        Expect::exception(NoDefaultValue::class)
            ->withMessageContaining('no safe default')
            ->withMessageContaining('when(fn () => $double->method(...))->returns(...)');

        TypeDefaultResolver::forSignature('Contract', $this->signature('\\DateTimeImmutable'), 'method', new RuntimeContext());
    }


    public function aQualifiedClassNameResolvesLikeItsShortName(): void
    {
        // Signatures render class types with a leading backslash, so the
        // resolver has to strip it before matching.
        Assert::instanceOf($this->resolve('\Generator'), \Generator::class);
        Assert::instanceOf($this->resolve('\Traversable'), \EmptyIterator::class);
        Assert::instanceOf($this->resolve('\Iterator'), \EmptyIterator::class);
        Assert::instanceOf($this->resolve('\ArrayIterator'), \ArrayIterator::class);
        Assert::instanceOf($this->resolve('\Closure'), \Closure::class);
    }

    public function anIteratorDefaultIsAnEmptyIterator(): void
    {
        Assert::instanceOf($this->resolve('Iterator'), \EmptyIterator::class);
    }

    public function anArrayIteratorDefaultIsItsOwnEmptyInstance(): void
    {
        // `\EmptyIterator` is not an `\ArrayIterator`, so the narrower type
        // needs its own default.
        $value = $this->resolve('ArrayIterator');

        Assert::instanceOf($value, \ArrayIterator::class);
        Assert::same(iterator_to_array($value), []);
    }

    public function aClosureDefaultIsAClosure(): void
    {
        Assert::instanceOf($this->resolve('Closure'), \Closure::class);
    }

    public function aDnfUnionSplitsOnTheTopLevelOnly(): void
    {
        // `(A&B)|null` is two branches: splitting inside the parentheses
        // would leave `(A` and `B)` as types nobody can default.
        Assert::null($this->resolve('(\ArrayObject&\Countable)|null'));
    }

    public function aDnfUnionWithoutNullFallsBackToTheBranchThatHasADefault(): void
    {
        Assert::same($this->resolve('(\ArrayObject&\Countable)|string'), '');
    }

    private function resolve(string $returnType): mixed
    {
        return TypeDefaultResolver::forSignature('Contract', $this->signature($returnType), 'method', new RuntimeContext());
    }

    private function signature(string $returnType): MethodSignature
    {
        return new MethodSignature(
            name: 'method',
            parameters: '',
            arguments: '[]',
            returnType: $returnType,
            returnsNever: false,
            returnsVoid: $returnType === 'void',
            returnsReference: false,
        );
    }
}
