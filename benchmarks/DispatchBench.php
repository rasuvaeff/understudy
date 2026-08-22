<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Benchmarks;

use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Understudy;
use Testo\Bench;

use function Rasuvaeff\Understudy\when;

/**
 * The three costs worth watching: generating a class the first time, creating
 * another instance of an already generated one, and answering a call.
 */
final class DispatchBench
{
    #[Bench]
    public function createDouble(): void
    {
        Understudy::for(BookRepository::class);
        Understudy::reset();
    }

    #[Bench]
    public function createDoubleWarm(): void
    {
        // The class already exists; this measures instantiation and bookkeeping.
        Understudy::for(BookRepository::class);
    }

    #[Bench]
    public function recordExpectation(): void
    {
        $repository = Understudy::for(BookRepository::class);

        when(fn () => $repository->count())->returns(1);
    }

    #[Bench]
    public function dispatchStubbedCall(): void
    {
        $repository = Understudy::for(BookRepository::class);
        when(fn () => $repository->count())->returns(1);

        $repository->count();
    }

    #[Bench]
    public function dispatchLooseCall(): void
    {
        Understudy::for(BookRepository::class)->count();
    }
}
