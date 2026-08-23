<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Support;

use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;

/**
 * Mutable holder for the system-under-test side of the engine lifecycle
 * property. The double itself carries no counters — that is the library's
 * whole point — so branch classification for the coverage gates accumulates
 * here instead, written by {@see EngineCommand} runs.
 */
final class EngineHarness
{
    public int $stubAnswers = 0;
    public int $countedClaims = 0;
    public int $unmatchedDefaults = 0;
    public int $verifiesPassed = 0;
    public int $verifiesFailed = 0;
    public int $settledCheckpoints = 0;

    public function __construct(public readonly BookRepository $double) {}
}
