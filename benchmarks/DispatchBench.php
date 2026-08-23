<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Benchmarks;

use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Tests\Fixture\Clock;
use Rasuvaeff\Understudy\Understudy;
use Testo\Bench;

use function Rasuvaeff\Understudy\when;

/**
 * Testo's benchmarks are comparative, so every scenario here is measured
 * against the thing an understudy replaces: a fake written by hand.
 *
 * That baseline is the honest one. It is also the demanding one — a hand
 * written fake does nothing but return, while an understudy matches
 * expectations and records the call.
 */
final class DispatchBench
{
    private static ?Clock $stubbedDouble = null;

    private static ?Clock $looseDouble = null;

    private static ?Clock $handwritten = null;

    // --- Creating -----------------------------------------------------------

    /**
     * The generated class is compiled once per process and cached, so this
     * measures instantiation and bookkeeping, not code generation. Cold
     * generation cannot be benchmarked in this harness: `reset()` clears
     * expectations, and neither it nor anything else can unload a class PHP
     * has already declared.
     */
    #[Bench(['handwritten fake' => [self::class, 'createHandwritten']], calls: 10_000)]
    public static function createUnderstudy(): void
    {
        Understudy::for(BookRepository::class);
    }

    public static function createHandwritten(): void
    {
        new HandwrittenRepository();
    }

    // --- Dispatching a configured call --------------------------------------

    #[Bench(['handwritten fake' => [self::class, 'callHandwritten']], calls: 10_000)]
    public static function dispatchStubbedCall(): void
    {
        self::$stubbedDouble ??= self::makeStubbed();

        self::$stubbedDouble->now();
    }

    public static function callHandwritten(): void
    {
        self::$handwritten ??= new HandwrittenClock();

        self::$handwritten->now();
    }

    // --- Dispatching an unconfigured call -----------------------------------

    /**
     * A loose double walks its expectations, finds nothing, and falls back to
     * the type-safe default — the path most calls in a real test suite take.
     */
    #[Bench(['handwritten fake' => [self::class, 'callHandwritten']], calls: 10_000)]
    public static function dispatchLooseCall(): void
    {
        self::$looseDouble ??= Understudy::for(Clock::class);

        self::$looseDouble->now();
    }

    private static function makeStubbed(): Clock
    {
        $double = Understudy::for(Clock::class);
        when(static fn(): int => $double->now())->returns(1_700_000_000);

        return $double;
    }
}

/**
 * @internal the baseline every scenario above is compared against
 */
final class HandwrittenClock implements Clock
{
    #[\Override]
    public function now(): int
    {
        return 1_700_000_000;
    }
}

/**
 * @internal
 */
final class HandwrittenRepository implements BookRepository
{
    #[\Override]
    public function find(int $id): ?Book
    {
        return null;
    }

    #[\Override]
    public function save(Book $book): void {}

    #[\Override]
    public function titles(): array
    {
        return [];
    }

    #[\Override]
    public function count(): int
    {
        return 0;
    }

    #[\Override]
    public function abort(string $reason): never
    {
        throw new \LogicException($reason);
    }

    #[\Override]
    public function stream(): \Generator
    {
        yield from [];
    }

    #[\Override]
    public function describe(): string|int
    {
        return '';
    }

    #[\Override]
    public function tag(string $name, int $weight = 1): string
    {
        return $name;
    }
}
