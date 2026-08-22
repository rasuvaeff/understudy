<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Defaults;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Codegen\MethodSignature;
use Rasuvaeff\Understudy\Defaults\TypeDefaultResolver;
use Rasuvaeff\Understudy\Exception\NoDefaultValue;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\Order;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The promise that makes loose mode usable: for any declared return type the
 * resolver either answers with a value that type accepts, or refuses with
 * `NoDefaultValue` — and it never invents a value by running someone else's
 * constructor.
 */
#[Test]
#[Covers(TypeDefaultResolver::class)]
final class TypeDefaultPropertyTest
{
    /** Every leaf type the resolver claims to know. */
    private const array SAFE_LEAVES = [
        'void', 'null', 'mixed', 'bool', 'false', 'true', 'int', 'float', 'string',
        'array', 'iterable', 'object', 'callable', 'Closure', 'Generator',
        'Traversable', 'Iterator', 'ArrayIterator',
    ];

    /**
     * Types the resolver must refuse rather than conjure. They are drawn far
     * more often than their share of the alphabet, because otherwise the
     * refusal branch is too rare to be exercised — eighteen safe leaves against
     * two unsafe ones puts it under the coverage gate.
     */
    private const array VALUE_OBJECTS = [
        '\\' . Book::class,
        '\\' . Order::class,
    ];

    #[Property(runs: 400)]
    public function aDefaultIsEitherAcceptedByItsTypeOrRefused(string $type): void
    {
        $refused = false;
        $value = null;

        try {
            /** @var mixed $value */
            $value = TypeDefaultResolver::forSignature('Contract', self::signature($type), 'method');
        } catch (NoDefaultValue) {
            $refused = true;
        }

        Classify::cover($refused, 'refused, with no safe value', 5);
        Classify::cover(!$refused, 'answered with a safe value', 40);
        Classify::when(str_contains($type, '|'), 'a union');
        Classify::when(str_starts_with($type, '?'), 'a nullable');

        if ($refused) {
            return;
        }

        Assert::true(self::accepts($type, $value));

        // The refusal is the point: a value object must never be conjured by
        // running its constructor, so if one comes back it can only have come
        // from another branch of a union.
        Assert::false($value instanceof Book);
        Assert::false($value instanceof Order);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function aDefaultIsEitherAcceptedByItsTypeOrRefusedGenerators(): array
    {
        $leaf = Gen::frequency([
            [3, Gen::elements(self::SAFE_LEAVES)],
            [2, Gen::elements(self::VALUE_OBJECTS)],
        ]);

        return [
            'type' => Gen::frequency([
                [3, $leaf],
                [2, Gen::map($leaf, static fn(string $type): string => '?' . $type)],
                [2, Gen::map(
                    Gen::uniqueArrayOf($leaf, 2, 3),
                    static fn(array $branches): string => implode('|', $branches),
                )],
            ]),
        ];
    }

    /**
     * The cases that shaped the resolver, pinned before the random phase.
     *
     * @return iterable<string, array{string}>
     */
    public static function aDefaultIsEitherAcceptedByItsTypeOrRefusedExamples(): iterable
    {
        yield 'a generator must not be an array' => ['Generator'];
        yield 'a nullable takes the null branch' => ['?' . Book::class];
        yield 'a union prefers null' => ['\\' . Book::class . '|null'];
        yield 'a union falls back to a safe branch' => ['\\' . Book::class . '|string'];
        yield 'a lone value object is refused' => ['\\' . Book::class];
        yield 'a union of value objects is refused' => ['\\' . Book::class . '|\\' . Order::class];
    }

    /**
     * Whether a value satisfies a rendered return type, decided here rather
     * than by asking the resolver under test.
     */
    private function accepts(string $type, mixed $value): bool
    {
        if (str_starts_with($type, '?')) {
            return $value === null || $this->accepts(substr($type, 1), $value);
        }

        if (str_contains($type, '|')) {
            foreach (explode('|', $type) as $branch) {
                if ($this->accepts($branch, $value)) {
                    return true;
                }
            }

            return false;
        }

        return match (ltrim($type, '\\')) {
            'void', 'null' => $value === null,
            'mixed' => true,
            'bool' => is_bool($value),
            'false' => $value === false,
            'true' => $value === true,
            'int' => is_int($value),
            'float' => is_float($value),
            'string' => is_string($value),
            'array' => is_array($value),
            'iterable' => is_iterable($value),
            'callable' => is_callable($value),
            'object' => is_object($value),
            default => $value instanceof (ltrim($type, '\\')),
        };
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
