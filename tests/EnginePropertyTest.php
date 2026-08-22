<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Cardinality;
use Rasuvaeff\Understudy\Exception\StrictModeViolation;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\FailureReport;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Outcome;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Runtime\Runtime;
use Rasuvaeff\Understudy\Tests\Fixture\Book;
use Rasuvaeff\Understudy\Tests\Fixture\BookRepository;
use Rasuvaeff\Understudy\Tests\Support\EngineGenerators;
use Rasuvaeff\Understudy\Understudy;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\when;

/**
 * The laws the engine obeys, as opposed to the cases it handles: §8 of the
 * plan.
 *
 * Every property here drives a method that takes arguments, which is not
 * incidental. With a zero-argument method every specification matches every
 * call, so precedence, filtering and accounting all collapse into "the only
 * expectation answered" — and the property passes without exercising any of
 * them.
 */
#[Test]
#[Covers(Understudy::class)]
#[Covers(Cardinality::class)]
#[Covers(Runtime::class)]
#[Covers(DoubleState::class)]
#[Covers(Expectation::class)]
#[Covers(Invocation::class)]
#[Covers(Outcome::class)]
#[Covers(FailureReport::class)]
#[Covers(StrictModeViolation::class)]
#[Covers(VerificationFailed::class)]
final class EnginePropertyTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        Understudy::reset();
    }

    // --- The call log --------------------------------------------------------

    /**
     * The log is exactly the sequence that was dispatched: the same calls with
     * the same arguments, in order, one entry each.
     */
    #[Property(runs: 200)]
    public function theLogIsExactlyWhatWasDispatched(array $script): void
    {
        $repository = Understudy::for(BookRepository::class);

        foreach ($script as [$method, $arguments]) {
            $this->dispatch($repository, $method, $arguments);
        }

        $log = Understudy::calls(fn() => $repository->find(Arg::any()));
        $finds = array_values(array_filter(
            $script,
            static fn(array $step): bool => $step[0] === 'find',
        ));

        Assert::same(count($log), count($finds));

        foreach ($log as $position => $invocation) {
            Assert::same($invocation->method, 'find');
            Assert::same($invocation->args, $finds[$position][1]);
        }

        // Filtering must not reorder what it filters.
        $sequences = array_map(static fn(Invocation $call): int => $call->sequence, $log);
        $ascending = $sequences;
        sort($ascending);

        Assert::same($sequences, $ascending);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function theLogIsExactlyWhatWasDispatchedGenerators(): array
    {
        return ['script' => EngineGenerators::callScript()];
    }

    /**
     * An argument left at its default is recorded as the value the method
     * actually received, so `tag('a')` and `tag('a', 1)` are one call.
     */
    #[Property(runs: 150)]
    public function anOmittedArgumentIsLoggedAsItsDefault(string $name): void
    {
        $repository = Understudy::for(BookRepository::class);

        $repository->tag($name);

        $calls = Understudy::calls(fn() => $repository->tag($name, 1));

        Assert::same(count($calls), 1);
        Assert::same($calls[0]->args, [$name, 1]);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function anOmittedArgumentIsLoggedAsItsDefaultGenerators(): array
    {
        return ['name' => Gen::stringFrom('abcdef', 1, 5)];
    }

    // --- Matching precedence -------------------------------------------------

    /**
     * The answering specification is the last registered one whose predicate
     * accepts the call: later specifications that do not match are skipped,
     * and earlier matching ones stay reachable underneath.
     */
    #[Property(runs: 300)]
    public function theAnsweringStubIsTheLastMatchingOne(array $specs, int $id): void
    {
        $repository = Understudy::for(BookRepository::class);

        foreach ($specs as $index => $spec) {
            EngineGenerators::registerSpec($repository, $spec, new Book('spec-' . $index));
        }

        // The oracle walks the specifications backwards, deciding acceptance
        // without the matcher implementation under test.
        $expected = null;

        foreach (array_reverse($specs, preserve_keys: true) as $index => $spec) {
            if (EngineGenerators::specAccepts($spec, $id)) {
                $expected = 'spec-' . $index;

                break;
            }
        }

        Classify::cover($expected !== null, 'a specification answered', 30);
        Classify::cover($expected === null, 'no specification matched', 5);

        Assert::same($repository->find($id)?->title, $expected);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function theAnsweringStubIsTheLastMatchingOneGenerators(): array
    {
        return [
            'specs' => Gen::nonEmptyArrayOf(EngineGenerators::specification(), 5),
            'id' => Gen::intBetween(0, 9),
        ];
    }

    /**
     * The layerings that matter, pinned before the random phase.
     *
     * @return iterable<string, array{list<array{string, int, int}>, int}>
     */
    public static function theAnsweringStubIsTheLastMatchingOneExamples(): iterable
    {
        yield 'a literal over a catch-all' => [[['any', 0, 0], ['literal', 3, 3]], 3];
        yield 'a catch-all over a literal' => [[['literal', 3, 3], ['any', 0, 0]], 3];
        yield 'a literal that misses' => [[['literal', 3, 3]], 4];
        yield 'a range that contains the id' => [[['range', 2, 5]], 3];
        yield 'a range that misses the id' => [[['range', 2, 5]], 9];
    }

    // --- Dispatcher branches -------------------------------------------------

    /**
     * Whichever branch answers — a matched expectation, a loose default, or a
     * strict refusal — the call is logged exactly once, with exactly one of
     * the two outcomes recorded.
     */
    #[Property(runs: 300)]
    public function everyBranchLogsTheCallExactlyOnce(bool $strict, bool $stubbed, int $id): void
    {
        $repository = Understudy::for(BookRepository::class);

        if ($strict) {
            Understudy::strict($repository);
        }

        if ($stubbed) {
            when(fn() => $repository->find($id))->returns(new Book('stubbed'));
        }

        Classify::cover($stubbed, 'an expectation answered', 25);
        Classify::cover(!$stubbed && !$strict, 'a loose default answered', 15);
        Classify::cover(!$stubbed && $strict, 'a strict double refused', 15);

        $threw = false;

        try {
            $repository->find($id);
        } catch (StrictModeViolation) {
            $threw = true;
        }

        Assert::same($threw, $strict && !$stubbed);

        $calls = Understudy::calls(fn() => $repository->find($id));

        Assert::same(count($calls), 1);
        Assert::same($calls[0]->didReturn(), !$threw);
        Assert::same($calls[0]->didThrow(), $threw);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function everyBranchLogsTheCallExactlyOnceGenerators(): array
    {
        return [
            'strict' => Gen::bool(),
            'stubbed' => Gen::bool(),
            'id' => Gen::intBetween(0, 5),
        ];
    }

    // --- Cardinality ---------------------------------------------------------

    /**
     * verify() passes exactly when the number of matching calls lies inside
     * the requested bounds — open-ended bounds included.
     */
    #[Property(runs: 300)]
    public function verifyPassesExactlyWhenTheCountIsAllowed(
        int $calls,
        int $minimum,
        int $slack,
        bool $bounded,
    ): void {
        $maximum = $bounded ? $minimum + $slack : null;
        $repository = Understudy::for(BookRepository::class);

        for ($i = 0; $i < $calls; $i++) {
            $repository->find(1);
        }

        $allowed = $calls >= $minimum && ($maximum === null || $calls <= $maximum);

        Classify::cover($allowed, 'within bounds', 20);
        Classify::cover(!$allowed, 'outside bounds', 15);
        // Without this branch the open-ended path through verify() is never
        // taken, and the property looks broader than it is.
        Classify::cover(!$bounded, 'no upper bound', 20);

        $passed = true;

        try {
            Understudy::verify(fn() => $repository->find(1), minimum: $minimum, maximum: $maximum);
        } catch (VerificationFailed) {
            $passed = false;
        }

        Assert::same($passed, $allowed);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function verifyPassesExactlyWhenTheCountIsAllowedGenerators(): array
    {
        return [
            'calls' => Gen::intBetween(0, 6),
            // Constructed, not filtered: a maximum below the minimum is not a
            // case this property is about.
            'minimum' => Gen::intBetween(0, 4),
            'slack' => Gen::intBetween(0, 4),
            'bounded' => Gen::bool(),
        ];
    }

    /**
     * A closed cardinality accepts exactly its interval; an open one accepts
     * everything from its minimum upwards and nothing below it.
     */
    #[Property(runs: 400)]
    public function cardinalityIsExactlyItsInterval(int $minimum, int $slack, int $count, bool $bounded): void
    {
        $cardinality = $bounded
            ? Cardinality::between($minimum, $minimum + $slack)
            : Cardinality::atLeast($minimum);

        $expected = $count >= $minimum && (!$bounded || $count <= $minimum + $slack);

        Classify::cover($expected, 'accepted', 20);
        Classify::cover(!$expected, 'rejected', 20);

        Assert::same($cardinality->allows($count), $expected);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function cardinalityIsExactlyItsIntervalGenerators(): array
    {
        return [
            'minimum' => Gen::intBetween(0, 8),
            'slack' => Gen::intBetween(0, 8),
            'count' => Gen::intBetween(0, 20),
            'bounded' => Gen::bool(),
        ];
    }

    /**
     * @return iterable<string, array{int, int, int, bool}>
     */
    public static function cardinalityIsExactlyItsIntervalExamples(): iterable
    {
        yield 'a zero interval accepts only zero' => [0, 0, 0, true];
        yield 'a zero interval rejects one' => [0, 0, 1, true];
        yield 'a point interval accepts its point' => [3, 0, 3, true];
        yield 'an open bound rejects below itself' => [3, 0, 2, false];
    }

    // --- Action chains -------------------------------------------------------

    /**
     * The k-th call is answered by the k-th link of the chain, and by its last
     * link once the chain has run out.
     */
    #[Property(runs: 200)]
    public function theChainAnswersLinkByLinkThenRepeats(array $values, int $calls): void
    {
        $repository = Understudy::for(BookRepository::class);
        $builder = when(fn() => $repository->find(1))->returns(new Book((string) $values[0]));

        foreach (array_slice($values, 1) as $value) {
            $builder->then()->returns(new Book((string) $value));
        }

        $last = count($values) - 1;

        for ($call = 0; $call < $calls; $call++) {
            Assert::same($repository->find(1)?->title, (string) $values[min($call, $last)]);
        }
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function theChainAnswersLinkByLinkThenRepeatsGenerators(): array
    {
        return [
            // Unique, so an answer from the wrong link cannot look right.
            'values' => Gen::uniqueArrayOf(Gen::intBetween(0, 99), 1, 5),
            'calls' => Gen::intBetween(1, 9),
        ];
    }

    // --- The ledger ----------------------------------------------------------

    /**
     * `allVerified()` holds exactly when both halves hold: every expectation
     * satisfied, and no call left unaccounted for.
     */
    #[Property(runs: 250)]
    public function allVerifiedIsExactlyItsTwoHalves(
        int $expected,
        bool $asExpected,
        int $otherwise,
        int $extra,
    ): void {
        // Drawn as "does the count match?" rather than as two independent
        // numbers: independent draws make the interesting case — both halves
        // holding — rare enough to sit on the coverage threshold and flicker.
        $actual = $asExpected ? $expected : $otherwise;
        $repository = Understudy::for(BookRepository::class);

        expect(fn() => $repository->find(1))->times($expected);

        for ($i = 0; $i < $actual; $i++) {
            $repository->find(1);
        }

        // A call to another method cannot be accounted for by that expectation.
        for ($i = 0; $i < $extra; $i++) {
            $repository->count();
        }

        $expectationsHold = $actual === $expected;
        $nothingLeftOver = $extra === 0;

        Classify::cover($expectationsHold && $nothingLeftOver, 'both halves hold', 20);
        Classify::cover(!$expectationsHold, 'an expectation fails', 15);
        Classify::cover($expectationsHold && !$nothingLeftOver, 'a call is unaccounted for', 5);

        $held = true;

        try {
            Understudy::allVerified($repository);
        } catch (VerificationFailed) {
            $held = false;
        }

        Assert::same($held, $expectationsHold && $nothingLeftOver);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function allVerifiedIsExactlyItsTwoHalvesGenerators(): array
    {
        return [
            'expected' => Gen::intBetween(0, 3),
            'asExpected' => Gen::bool(),
            'otherwise' => Gen::intBetween(0, 3),
            // Weighted towards nothing left over, for the same reason.
            'extra' => Gen::frequency([
                [3, Gen::constant(0)],
                [1, Gen::intBetween(1, 2)],
            ]),
        ];
    }

    /**
     * @return iterable<string, array{int, bool, int, int}>
     */
    public static function allVerifiedIsExactlyItsTwoHalvesExamples(): iterable
    {
        yield 'nothing expected, nothing happened' => [0, true, 0, 0];
        yield 'expected once, happened once' => [1, true, 0, 0];
        yield 'expected once, happened twice' => [1, false, 2, 0];
        yield 'satisfied but something else happened' => [1, true, 0, 1];
    }

    /**
     * Verifications are order-independent: the same claims in any order reach
     * the same conclusion, because each counts the whole log and marks
     * idempotently what it matched.
     */
    #[Property(runs: 200)]
    public function theOrderOfVerificationsDoesNotMatter(array $order): void
    {
        $repository = Understudy::for(BookRepository::class);

        $repository->find(1);
        $repository->find(2);
        $repository->find(1);

        foreach ($order as $claim) {
            match ($claim) {
                'one' => Understudy::verify(fn() => $repository->find(1), times: 2),
                'two' => Understudy::verify(fn() => $repository->find(2), times: 1),
                default => Understudy::verify(fn() => $repository->find(Arg::any()), times: 3),
            };
        }

        Understudy::nothingElse($repository);

        Assert::same(count(Understudy::calls(fn() => $repository->find(Arg::any()))), 3);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function theOrderOfVerificationsDoesNotMatterGenerators(): array
    {
        // The permutations spelled out rather than drawn by rejection:
        // uniqueArrayOf(…, 3, 3) has to keep re-drawing until it happens to
        // hit all three values, and can give up with GenerationExhausted.
        return [
            'order' => Gen::elements([
                ['one', 'two', 'any'],
                ['one', 'any', 'two'],
                ['two', 'one', 'any'],
                ['two', 'any', 'one'],
                ['any', 'one', 'two'],
                ['any', 'two', 'one'],
            ]),
        ];
    }

    // --- Determinism ---------------------------------------------------------

    /**
     * The same script produces the same answers *and* the same log: nothing in
     * the engine depends on anything but its own state.
     */
    #[Property(runs: 150)]
    public function theEngineIsDeterministic(array $values, array $script): void
    {
        $first = $this->runScenario($values, $script);
        Understudy::reset();
        $second = $this->runScenario($values, $script);

        Assert::same($first, $second);
    }

    /**
     * @return array<string, ArbitraryInterface>
     */
    public static function theEngineIsDeterministicGenerators(): array
    {
        return [
            'values' => Gen::uniqueArrayOf(Gen::intBetween(0, 50), 1, 4),
            'script' => EngineGenerators::callScript(),
        ];
    }

    /**
     * @param non-empty-list<int>              $values
     * @param list<array{string, list<mixed>}> $script
     *
     * @return array{answers: list<string>, log: list<array{string, list<mixed>, int}>}
     */
    private function runScenario(array $values, array $script): array
    {
        $repository = Understudy::for(BookRepository::class);
        $builder = when(fn() => $repository->find(Arg::any()))->returns(new Book((string) $values[0]));

        foreach (array_slice($values, 1) as $value) {
            $builder->then()->returns(new Book((string) $value));
        }

        $answers = [];

        foreach ($script as [$method, $arguments]) {
            $answers[] = $this->describe($this->dispatch($repository, $method, $arguments));
        }

        $log = array_map(
            static fn(Invocation $call): array => [$call->method, $call->args, $call->sequence],
            Understudy::calls(fn() => $repository->find(Arg::any())),
        );

        return ['answers' => $answers, 'log' => $log];
    }

    /**
     * @param list<mixed> $arguments
     */
    private function dispatch(BookRepository $repository, string $method, array $arguments): mixed
    {
        return match ($method) {
            'find' => $repository->find($arguments[0]),
            'tag' => $repository->tag($arguments[0], $arguments[1]),
            default => $repository->count(),
        };
    }

    private function describe(mixed $answer): string
    {
        return $answer instanceof Book ? 'book:' . $answer->title : get_debug_type($answer);
    }
}
