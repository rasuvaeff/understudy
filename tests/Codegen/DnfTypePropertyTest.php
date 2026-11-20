<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Codegen;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Codegen\DnfType;
use Rasuvaeff\Understudy\Codegen\MethodSignature;
use Rasuvaeff\Understudy\Expectation\ReturnContract;
use Rasuvaeff\Understudy\Tests\Support\TypeGenerators;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * The laws the type model owes the unifier and the return contract.
 *
 * `satisfies` is subtyping, so it is a preorder: reflexive and transitive,
 * with an intersection below each of its operands. `ReturnContract` is
 * checked against PHP itself — a value a real closure declaring the type
 * returns without a `TypeError` must never be refused at registration.
 */
#[Test]
#[Covers(DnfType::class)]
#[Covers(ReturnContract::class)]
final class DnfTypePropertyTest
{
    /** @var array<string, \Closure(mixed): mixed> */
    private static array $probes = [];

    #[Property(runs: 300)]
    public function satisfiesIsReflexive(DnfType $type): void
    {
        Assert::true($type->satisfies($type));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function satisfiesIsReflexiveGenerators(): array
    {
        return ['type' => TypeGenerators::dnfType()];
    }

    #[Property(runs: 500)]
    public function satisfiesIsTransitive(DnfType $a, DnfType $b, DnfType $c): void
    {
        $chained = $a->satisfies($b) && $b->satisfies($c);
        Classify::when($chained, 'chained');

        if ($chained) {
            Assert::true($a->satisfies($c));
        }
    }

    /**
     * One known gap, pinned rather than generated: the generated `static`
     * satisfies every target's `self`, a target's `self` satisfies its own
     * class, yet `static` alone does not satisfy that class — the marker
     * carries no class to ask. Conservative (a pair may be refused that PHP
     * would accept), never a fatal, and older than the type model.
     */
    public function theGeneratedStaticIsNotTransitiveThroughATargetSelf(): void
    {
        $static = DnfType::of([[DnfType::GENERATED_STATIC]]);
        $self = DnfType::of([[DnfType::TARGET_SELF . 'Countable']]);
        $class = DnfType::parse('\\Countable');

        Assert::true($static->satisfies($self));
        Assert::true($self->satisfies($class));
        Assert::false($static->satisfies($class));
    }

    /**
     * Without a narrowing step most random triples are unrelated and the
     * implication holds vacuously; widening `b` and `c` from `a` makes the
     * chain the common case.
     *
     * @return array<string, ArbitraryInterface>
     */
    public static function satisfiesIsTransitiveGenerators(): array
    {
        $type = TypeGenerators::renderableDnfType();

        return [
            'a' => $type,
            'b' => Gen::frequency([[1, $type], [1, Gen::elements([DnfType::parse('mixed'), DnfType::parse('object|iterable|int'), DnfType::parse('\\Traversable|\\Countable|null')])]]),
            'c' => Gen::frequency([[1, $type], [1, Gen::elements([DnfType::parse('mixed'), DnfType::parse('object|iterable|int|null')])]]),
        ];
    }

    /**
     * @return iterable<string, array{DnfType, DnfType, DnfType}>
     */
    public static function satisfiesIsTransitiveExamples(): iterable
    {
        yield 'a class, its interface, object' => [
            DnfType::parse('\\ArrayIterator'),
            DnfType::parse('\\Traversable'),
            DnfType::parse('object'),
        ];
        yield 'a literal through bool to mixed' => [
            DnfType::parse('true'),
            DnfType::parse('bool'),
            DnfType::parse('mixed'),
        ];
    }

    #[Property(runs: 300)]
    public function anIntersectionSatisfiesEachOperand(string $left, string $right): void
    {
        $intersection = DnfType::of([[$left, $right]]);

        Assert::true($intersection->satisfies(DnfType::of([[$left]])));
        Assert::true($intersection->satisfies(DnfType::of([[$right]])));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function anIntersectionSatisfiesEachOperandGenerators(): array
    {
        return [
            'left' => TypeGenerators::objectAtom(),
            'right' => TypeGenerators::objectAtom(),
        ];
    }

    #[Property(runs: 300)]
    public function eachBranchSatisfiesTheUnion(DnfType $type): void
    {
        foreach ($type->branches as $branch) {
            Assert::true(DnfType::of([$branch])->satisfies($type));
        }
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function eachBranchSatisfiesTheUnionGenerators(): array
    {
        return ['type' => TypeGenerators::dnfType()];
    }

    #[Property(runs: 300)]
    public function renderingRoundTripsThroughParse(DnfType $type): void
    {
        $rendered = DnfType::parse($type->render());

        Assert::same($rendered->render(), $type->render());
        Assert::true($rendered->satisfies($type));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function renderingRoundTripsThroughParseGenerators(): array
    {
        return ['type' => TypeGenerators::renderableDnfType()];
    }

    /**
     * Differential against the engine: a generated method is not under
     * `strict_types`, and neither is this probe. Whatever it returns without
     * a `TypeError`, `returns()` must accept — the contract is allowed to be
     * looser than PHP, never stricter.
     */
    #[Property(runs: 500)]
    public function theReturnContractNeverRefusesWhatPhpReturns(string $type, mixed $value): void
    {
        $returned = self::phpReturns($type, $value);
        Classify::cover($returned, 'php returns it', 20.0);
        Classify::cover(!$returned, 'php refuses it', 20.0);

        if ($returned) {
            Assert::true(self::contract($type)->accepts($value));
        }
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function theReturnContractNeverRefusesWhatPhpReturnsGenerators(): array
    {
        return [
            'type' => TypeGenerators::declarableReturnType(),
            'value' => TypeGenerators::returnedValue(),
        ];
    }

    /**
     * And where the declared type has no scalar in it, there is no coercion
     * to be looser about: the contract and PHP agree exactly.
     */
    #[Property(runs: 300)]
    public function withoutScalarsTheContractAgreesWithPhp(string $type, mixed $value): void
    {
        Assert::same(self::contract($type)->accepts($value), self::phpReturns($type, $value));
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function withoutScalarsTheContractAgreesWithPhpGenerators(): array
    {
        return [
            'type' => Gen::elements([
                'array', 'iterable', 'object', '\\Countable', '\\Traversable', '\\ArrayIterator',
                '\\Closure', '\\stdClass', '?\\Countable', 'iterable|null', '\\Countable&\\Traversable',
                '(\\Countable&\\Traversable)|null',
            ]),
            'value' => TypeGenerators::returnedValue(),
        ];
    }

    /**
     * @param non-empty-string $type
     */
    private static function contract(string $type): ReturnContract
    {
        return new ReturnContract(
            label: 'Probe',
            method: 'probe',
            signature: new MethodSignature(
                name: 'probe',
                parameters: '',
                arguments: '[]',
                returnType: $type,
                returnsNever: false,
                returnsVoid: false,
                returnsReference: false,
            ),
            selfContract: \stdClass::class,
        );
    }

    private static function phpReturns(string $type, mixed $value): bool
    {
        /** @var \Closure(mixed): mixed $probe */
        $probe = self::$probes[$type] ??= eval('return static function (mixed $v): ' . $type . ' { return $v; };');

        // A fractional float returned as `int` is a deprecation, not a
        // refusal; it must not reach the runner as a warning.
        set_error_handler(static fn(): bool => true);

        try {
            $probe($value);

            return true;
        } catch (\TypeError) {
            return false;
        } finally {
            restore_error_handler();
        }
    }
}
