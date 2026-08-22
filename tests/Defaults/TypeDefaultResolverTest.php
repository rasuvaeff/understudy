<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Defaults;

use Rasuvaeff\Understudy\Codegen\MethodSignature;
use Rasuvaeff\Understudy\Defaults\TypeDefaultResolver;
use Rasuvaeff\Understudy\Exception\NoDefaultValue;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(TypeDefaultResolver::class)]
final class TypeDefaultResolverTest
{
    #[DataProvider('safeDefaultProvider')]
    public function returnsATypeSafeDefault(string $returnType, mixed $expected): void
    {
        Assert::same(TypeDefaultResolver::forSignature('Contract', $this->signature($returnType), 'method'), $expected);
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
        $value = TypeDefaultResolver::forSignature('Contract', $this->signature('object'), 'method');

        Assert::instanceOf($value, \stdClass::class);
    }

    public function generatorDefaultIsAnEmptyGeneratorNotAnArray(): void
    {
        // `[]` would violate a declared `: Generator`.
        $value = TypeDefaultResolver::forSignature('Contract', $this->signature('Generator'), 'method');

        Assert::instanceOf($value, \Generator::class);
        Assert::same(iterator_to_array($value), []);
    }

    public function traversableDefaultIsAnEmptyIterator(): void
    {
        $value = TypeDefaultResolver::forSignature('Contract', $this->signature('Traversable'), 'method');

        Assert::instanceOf($value, \EmptyIterator::class);
    }

    public function callableDefaultIsInvokable(): void
    {
        $value = TypeDefaultResolver::forSignature('Contract', $this->signature('callable'), 'method');

        Assert::true(is_callable($value));
    }

    public function anUnknownSignatureAnswersWithNull(): void
    {
        Assert::null(TypeDefaultResolver::forSignature('Contract', null, 'method'));
    }

    public function anArbitraryClassHasNoSafeDefault(): void
    {
        // Running someone else's constructor to invent a value, or handing back
        // an object whose constructor was skipped, are both worse than saying
        // there is nothing safe to return.
        Expect::exception(NoDefaultValue::class)
            ->withMessageContaining('no safe default')
            ->withMessageContaining('Understudy::defaults');

        TypeDefaultResolver::forSignature('Contract', $this->signature('\\DateTimeImmutable'), 'method');
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
