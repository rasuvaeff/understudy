<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\StateMachine\CommandSequence;
use Rasuvaeff\PropertyTesting\StateMachine\StateMachine;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Cardinality;
use Rasuvaeff\Understudy\Defaults\TypeDefaultResolver;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Tests\Support\ConfigureExpectCommand;
use Rasuvaeff\Understudy\Tests\Support\ConfigureStubCommand;
use Rasuvaeff\Understudy\Tests\Support\DispatchFindCommand;
use Rasuvaeff\Understudy\Tests\Support\EngineHarness;
use Rasuvaeff\Understudy\Tests\Support\EngineState;
use Rasuvaeff\Understudy\Tests\Support\RequireNothingElseCommand;
use Rasuvaeff\Understudy\Tests\Support\SettleCheckpointCommand;
use Rasuvaeff\Understudy\Tests\Support\VerifyCallsCommand;
use Rasuvaeff\Understudy\Tests\Support\VerifyEverythingCommand;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * Model-based test for the ledger lifecycle the plan calls the engine's
 * core loop: stub/expect → dispatch → verify → checkpoint. Any sequence of
 * configuration, dispatch and verification commands must keep the real
 * double's answers, its call log and its accounting in lock-step with the
 * pure {@see EngineState} model — most-recent-first matching, claim
 * accounting, verify marking and checkpoint settling included.
 *
 * The commands are deliberately drawn from a small closed world: one method
 * (`find(int): ?Book`), literal or wildcard arguments, plain `returns()`
 * stubs and action-less claims. Everything richer is pinned by the unit
 * suites; what only a long random interleaving can surface is exactly this
 * loop.
 */
#[Test]
#[Covers(Understudy::class)]
#[Covers(Runtime::class)]
#[Covers(DoubleState::class)]
#[Covers(Expectation::class)]
#[Covers(Cardinality::class)]
#[Covers(Invocation::class)]
#[Covers(TypeDefaultResolver::class)]
final class EngineLifecyclePropertyTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    #[Property(runs: 200)]
    public function theLedgerTracksTheModel(CommandSequence $sequence): void
    {
        $harness = null;

        StateMachine::check(
            $sequence,
            static function () use (&$harness): EngineHarness {
                /** @var EngineHarness $harness */
                $harness = new EngineHarness(Understudy::for(BookRepository::class));

                return $harness;
            },
        );

        \assert($harness instanceof EngineHarness);

        Classify::cover($harness->stubAnswers > 0, 'a stub answered', 5);
        Classify::cover($harness->countedClaims > 0, 'a matched claim fell through to the default', 5);
        Classify::cover($harness->unmatchedDefaults > 0, 'an unmatched call answered with the default', 5);
        Classify::cover($harness->verifiesPassed > 0, 'an explicit verify passed', 5);
        Classify::cover($harness->verifiesFailed > 0, 'an explicit verify failed', 5);
        Classify::cover($harness->settledCheckpoints > 0, 'a checkpoint settled', 5);
        Classify::cover($harness->refusedRegistrations > 0, 'a colliding registration was refused', 5);

        // The runner advanced its own copy of the model; folding the same
        // pure transitions here gives the end state the real log must have
        // reached — every dispatch recorded exactly once, whatever the
        // interleaving did to matching, accounting and settling in between.
        $model = $sequence->initialModel;
        \assert($model instanceof EngineState);

        foreach ($sequence->commands as $command) {
            if ($command->preCondition($model)) {
                $model = $command->nextState($model);
            }
        }

        Assert::same(count(Understudy::calls(fn() => $harness->double->find(Arg::any()))), $model->callCount());
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function theLedgerTracksTheModelGenerators(): array
    {
        return [
            // Swarmed: each sequence draws a random subset of the command
            // shapes, so phases that use only part of the vocabulary are an
            // ordinary case rather than a lucky roll.
            'sequence' => Gen::swarm(Gen::commands(
                new EngineState(),
                self::commandGenerators(),
                minLength: 0,
                maxLength: 24,
            )),
        ];
    }

    /**
     * The layerings the ledger got wrong before, pinned before the random
     * phase: the refused stub/claim collision, checkpoint survival,
     * accounting through verify.
     *
     * @return iterable<string, CommandSequence>
     */
    public static function theLedgerTracksTheModelExamples(): iterable
    {
        // The sequence that used to pin the silent shadowing: the claim is now
        // refused at registration, the stub keeps answering, and the call it
        // answers stays unaccounted — which nothingElse() then reports.
        yield 'a claim colliding with a stub is refused and the stub keeps answering' => [new CommandSequence(
            new EngineState(),
            [
                new ConfigureStubCommand(anyArgument: false, literalId: 2),
                new ConfigureExpectCommand(anyArgument: false, literalId: 2, minimum: 1, maximum: 1),
                new DispatchFindCommand(id: 2),
                new RequireNothingElseCommand(),
            ],
        )];

        yield 'a stub colliding with a wildcard claim is refused' => [new CommandSequence(
            new EngineState(),
            [
                new ConfigureExpectCommand(anyArgument: true, literalId: 0, minimum: 0, maximum: null),
                new ConfigureStubCommand(anyArgument: true, literalId: 0),
                new DispatchFindCommand(id: 1),
            ],
        )];

        yield 'checkpoint drops a satisfied claim but keeps the stub answering' => [new CommandSequence(
            new EngineState(),
            [
                new ConfigureStubCommand(anyArgument: false, literalId: 1),
                new ConfigureExpectCommand(anyArgument: true, literalId: 0, minimum: 0, maximum: null),
                new DispatchFindCommand(id: 1),
                new SettleCheckpointCommand(),
                new DispatchFindCommand(id: 1),
            ],
        )];

        yield 'verify accounts stubbed calls for nothingElse' => [new CommandSequence(
            new EngineState(),
            [
                new ConfigureStubCommand(anyArgument: false, literalId: 3),
                new DispatchFindCommand(id: 3),
                new RequireNothingElseCommand(),
                new VerifyCallsCommand(anyArgument: false, literalId: 3, minimum: 1, maximum: 1),
                new RequireNothingElseCommand(),
            ],
        )];

        yield 'a violated claim fails both verifyAll and the checkpoint' => [new CommandSequence(
            new EngineState(),
            [
                new ConfigureExpectCommand(anyArgument: false, literalId: 1, minimum: 2, maximum: null),
                new VerifyEverythingCommand(),
                new SettleCheckpointCommand(),
                new RequireNothingElseCommand(),
            ],
        )];
    }

    /**
     * @return list<ArbitraryInterface>
     */
    private static function commandGenerators(): array
    {
        $argument = Gen::bool();
        $literal = Gen::intBetween(1, 3);

        return [
            Gen::map(
                Gen::tuple($argument, $literal),
                static fn(array $tuple): ConfigureStubCommand
                    => new ConfigureStubCommand(anyArgument: $tuple[0], literalId: $tuple[1]),
            ),
            Gen::map(
                Gen::tuple(
                    $argument,
                    $literal,
                    Gen::intBetween(0, 2),
                    Gen::flatMap(
                        Gen::intBetween(0, 2),
                        static fn(int $minimum): ArbitraryInterface => Gen::elements([
                            null,
                            $minimum,
                            $minimum + 2,
                        ]),
                    ),
                ),
                static fn(array $tuple): ConfigureExpectCommand => new ConfigureExpectCommand(
                    anyArgument: $tuple[0],
                    literalId: $tuple[1],
                    minimum: $tuple[2],
                    maximum: self::atLeastMinimum($tuple[2], $tuple[3]),
                ),
            ),
            Gen::map(Gen::intBetween(1, 3), static fn(int $id): DispatchFindCommand => new DispatchFindCommand(id: $id)),
            Gen::map(
                Gen::tuple(
                    $argument,
                    $literal,
                    Gen::intBetween(0, 2),
                    Gen::flatMap(
                        Gen::intBetween(0, 2),
                        static fn(int $minimum): ArbitraryInterface => Gen::elements([
                            null,
                            $minimum,
                            $minimum + 2,
                        ]),
                    ),
                ),
                static fn(array $tuple): VerifyCallsCommand => new VerifyCallsCommand(
                    anyArgument: $tuple[0],
                    literalId: $tuple[1],
                    minimum: $tuple[2],
                    maximum: self::atLeastMinimum($tuple[2], $tuple[3]),
                ),
            ),
            Gen::constant(new VerifyEverythingCommand()),
            Gen::constant(new SettleCheckpointCommand()),
            Gen::constant(new RequireNothingElseCommand()),
        ];
    }

    /**
     * Keeps the drawn upper bound honest: never below the minimum it is
     * paired with, unbounded when the element draw said so.
     */
    private static function atLeastMinimum(int $minimum, ?int $drawn): ?int
    {
        return $drawn === null ? null : max($minimum, $drawn);
    }
}
