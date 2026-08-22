<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Support;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;

use function Rasuvaeff\Understudy\when;

/**
 * Generators and oracles shared by the engine properties.
 *
 * They live here rather than as private static helpers in the test class:
 * Rector's LocallyCalledStaticMethodToNonStaticRector converts such a helper
 * into an instance method as soon as a property body calls it, and a public
 * static `*Generators()` method then cannot reach it. That failure surfaces on
 * `composer release-check`, not on `composer build`.
 *
 * @psalm-type Specification = array{string, int, int}
 */
final class EngineGenerators
{
    private function __construct() {}

    /**
     * A script of calls across three methods with different arities, so that
     * specifications genuinely filter. With a zero-argument method every
     * specification matches every call and the properties pass without
     * exercising precedence at all.
     */
    public static function callScript(): ArbitraryInterface
    {
        return Gen::arrayOf(
            Gen::frequency([
                [3, Gen::map(
                    Gen::intBetween(0, 9),
                    static fn(int $id): array => ['find', [$id]],
                )],
                [2, Gen::map(
                    Gen::tuple(Gen::stringFrom('abc', 1, 3), Gen::intBetween(1, 4)),
                    static fn(array $pair): array => ['tag', [$pair[0], $pair[1]]],
                )],
                [1, Gen::constant(['count', []])],
            ]),
            0,
            8,
        );
    }

    /**
     * One `find()` specification: a literal id, an inclusive id range, or a
     * catch-all. Rendered as a tuple so the oracle can evaluate it without
     * asking the library under test.
     */
    public static function specification(): ArbitraryInterface
    {
        return Gen::frequency([
            [2, Gen::map(
                Gen::intBetween(0, 9),
                static fn(int $id): array => ['literal', $id, $id],
            )],
            [2, Gen::map(
                Gen::tuple(Gen::intBetween(0, 9), Gen::intBetween(0, 4)),
                static fn(array $pair): array => ['range', $pair[0], $pair[0] + $pair[1]],
            )],
            [1, Gen::constant(['any', 0, 0])],
        ]);
    }

    /**
     * @param array{string, int, int} $spec
     */
    public static function registerSpec(BookRepository $repository, array $spec, Book $answer): void
    {
        [$kind, $low, $high] = $spec;

        match ($kind) {
            'literal' => when(static fn(): ?Book => $repository->find($low))->returns($answer),
            'range' => when(
                static fn(): ?Book => $repository->find(Arg::int(min: $low, max: $high)),
            )->returns($answer),
            default => when(static fn(): ?Book => $repository->find(Arg::any()))->returns($answer),
        };
    }

    /**
     * The oracle: whether this specification accepts that id, decided without
     * the matcher implementation the property is testing.
     *
     * @param array{string, int, int} $spec
     */
    public static function specAccepts(array $spec, int $id): bool
    {
        [$kind, $low, $high] = $spec;

        return match ($kind) {
            'literal' => $id === $low,
            'range' => $id >= $low && $id <= $high,
            default => true,
        };
    }
}
