<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Codegen\DoubleFactory;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Runtime\DoubleState;
use Rasuvaeff\Understudy\Runtime\InvocationSignal;
use Rasuvaeff\Understudy\Runtime\Mode;
use Rasuvaeff\Understudy\Runtime\Runtime;

/**
 * The whole public surface, as static methods so that an understudy itself can
 * stay free of service members: every one of them would be a name the doubled
 * contract can no longer use.
 *
 * The same three verbs exist as free functions in this namespace — see
 * `functions.php` — and read better in tests. This class is the collision-free
 * form, which matters in Pest, where `expect()` is already taken.
 *
 * @api
 */
final class Understudy
{
    private function __construct() {}

    /**
     * Creates an understudy for one contract, optionally combined with further
     * interfaces.
     *
     * @template T of object
     *
     * @param class-string<T> $target
     * @param class-string    ...$interfaces
     *
     * @return T
     */
    public static function for(string $target, string ...$interfaces): object
    {
        $contracts = [$target, ...array_values($interfaces)];
        $blueprint = DoubleFactory::blueprintFor($contracts);
        // Reflection rather than `new $class()`: the generated class is not
        // statically known, and this is also the path a class double will need,
        // where the target's constructor must be skipped rather than run.
        /** @var T $double */
        $double = (new \ReflectionClass($blueprint->generatedClass))->newInstanceWithoutConstructor();

        Runtime::adopt($double, new DoubleState($blueprint));

        return $double;
    }

    /**
     * Stubs a call: allowed any number of times, including none.
     *
     * @param callable(): mixed $call
     *
     * @return WhenBuilder<mixed>
     */
    public static function when(callable $call): WhenBuilder
    {
        $signal = self::record($call);
        $expectation = new Expectation($signal->method, $signal->args);

        self::stateOf($signal->double)->addExpectation($expectation);

        return new WhenBuilder($expectation);
    }

    /**
     * Asserts, after the fact, how many times a call was made.
     *
     * @param callable(): mixed $call
     * @param int<0, max>|null  $times   exact count; defaults to at least one
     * @param int<0, max>|null  $minimum
     * @param int<0, max>|null  $maximum
     */
    public static function verify(
        callable $call,
        ?int $times = null,
        ?int $minimum = null,
        ?int $maximum = null,
        bool $never = false,
    ): void {
        $signal = self::record($call);
        $state = self::stateOf($signal->double);
        $probe = new Expectation($signal->method, $signal->args);

        $matches = 0;

        foreach ($state->callLog() as $invocation) {
            if ($probe->matches($invocation->method, $invocation->args)) {
                $matches++;
            }
        }

        [$low, $high] = match (true) {
            $never => [0, 0],
            $times !== null => [$times, $times],
            default => [$minimum ?? 1, $maximum],
        };

        if ($matches >= $low && ($high === null || $matches <= $high)) {
            return;
        }

        throw VerificationFailed::withReport(FailureReport::render(
            label: $state->label(),
            expectation: $probe->describe(),
            expectedLow: $low,
            expectedHigh: $high,
            actual: $matches,
            callLog: $state->callLog(),
            method: $signal->method,
            expectedArgs: $signal->args,
        ));
    }

    /**
     * Every recorded call matching the specification, in order.
     *
     * @param callable(): mixed $call
     *
     * @return list<Invocation>
     */
    public static function calls(callable $call): array
    {
        $signal = self::record($call);
        $probe = new Expectation($signal->method, $signal->args);

        return array_values(array_filter(
            self::stateOf($signal->double)->callLog(),
            static fn(Invocation $i): bool => $probe->matches($i->method, $i->args),
        ));
    }

    /**
     * Makes an understudy fail on any call no expectation matched.
     */
    public static function strict(object $double): void
    {
        self::stateOf($double)->setMode(Mode::Strict);
    }

    /**
     * Names one understudy in failure messages, which is what makes two
     * doubles of the same contract tellable apart.
     *
     * @param non-empty-string $label
     */
    public static function label(object $double, string $label): void
    {
        self::stateOf($double)->setLabel($label);
    }

    /**
     * Asserts that nothing was called on this understudy at all.
     */
    public static function unused(object $double): void
    {
        $state = self::stateOf($double);
        $log = $state->callLog();

        if ($log === []) {
            return;
        }

        throw VerificationFailed::withReport(sprintf(
            "Understudy `%s` was expected to be unused, but received %d call(s):\n%s",
            $state->label(),
            count($log),
            FailureReport::renderCallLog($log),
        ));
    }

    /**
     * Drops every context. Adapters call this after each test, unconditionally.
     */
    public static function reset(): void
    {
        Runtime::reset();
    }

    /**
     * Runs the specification closure with recording on, and catches the signal
     * the called method throws instead of returning.
     */
    private static function record(callable $call): InvocationSignal
    {
        $context = Runtime::current();
        $context->beginRecording();

        try {
            $call();
        } catch (InvocationSignal $signal) {
            return $signal;
        } catch (\Throwable $failure) {
            throw InvalidCallSpecification::closureFailed($failure);
        } finally {
            $context->endRecording();
        }

        throw InvalidCallSpecification::noCallRecorded();
    }

    private static function stateOf(object $double): DoubleState
    {
        $state = Runtime::stateOf($double);

        if ($state === null) {
            throw InvalidCallSpecification::noCallRecorded();
        }

        return $state;
    }
}
