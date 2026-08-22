<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;
use Rasuvaeff\Understudy\Matcher\ArgumentMatcher;

/**
 * Renders why a verification failed: what was expected, what happened, and —
 * where the method was called but with other arguments — which argument
 * differed, marked with asterisks.
 *
 * @internal
 */
final class FailureReport
{
    private function __construct() {}

    /**
     * @param non-empty-string  $label
     * @param non-empty-string  $expectation
     * @param int<0, max>       $expectedLow
     * @param int<0, max>|null  $expectedHigh
     * @param int<0, max>       $actual
     * @param list<Invocation>  $callLog
     * @param non-empty-string  $method
     * @param list<mixed>       $expectedArgs
     */
    public static function render(
        string $label,
        string $expectation,
        int $expectedLow,
        ?int $expectedHigh,
        int $actual,
        array $callLog,
        string $method,
        array $expectedArgs,
    ): string {
        $report = sprintf(
            "Understudy `%s` expected `%s` to be called %s, but %s.",
            $label,
            $expectation,
            self::describeCardinality($expectedLow, $expectedHigh),
            $actual === 0 ? 'it was never called' : sprintf('it was called %s', self::times($actual)),
        );

        $sameMethod = array_values(array_filter(
            $callLog,
            static fn(Invocation $i): bool => $i->method === $method,
        ));

        if ($sameMethod !== [] && $actual !== count($sameMethod)) {
            $report .= sprintf(
                "\n\nThe following calls to `%s` were made during this test:\n%s",
                $method,
                self::renderCallLog($sameMethod, $expectedArgs),
            );
        }

        return $report;
    }

    /**
     * One call as it was made, without the surrounding log.
     *
     * @return non-empty-string
     */
    public static function renderCall(Invocation $invocation): string
    {
        return $invocation->method . '(' . implode(', ', array_map(
            static fn(mixed $argument): string => ArgumentFormatter::format($argument),
            $invocation->args,
        )) . ')';
    }

    /**
     * @param list<Invocation> $callLog
     * @param list<mixed>      $expectedArgs marked arguments are the ones that differed
     */
    public static function renderCallLog(array $callLog, array $expectedArgs = []): string
    {
        $lines = [];

        foreach ($callLog as $invocation) {
            $arguments = [];

            /** @var mixed $argument */
            foreach ($invocation->args as $position => $argument) {
                $rendered = ArgumentFormatter::format($argument);
                $arguments[] = self::differs($expectedArgs, $position, $argument)
                    ? '*' . $rendered . '*'
                    : $rendered;
            }

            $lines[] = sprintf('    %s(%s)', $invocation->method, implode(', ', $arguments));
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<mixed> $expectedArgs
     */
    private static function differs(array $expectedArgs, int $position, mixed $actual): bool
    {
        if (!array_key_exists($position, $expectedArgs)) {
            return $expectedArgs !== [];
        }

        /** @var mixed $expected */
        $expected = $expectedArgs[$position];

        return $expected instanceof ArgumentMatcher
            ? !$expected->matches($actual)
            : $expected !== $actual;
    }

    /**
     * @param int<0, max>      $low
     * @param int<0, max>|null $high
     *
     * @return non-empty-string
     */
    private static function describeCardinality(int $low, ?int $high): string
    {
        return match (true) {
            $low === 0 && $high === 0 => 'never',
            $high === null => sprintf('at least %s', self::times($low)),
            $low === $high => sprintf('exactly %s', self::times($low)),
            default => sprintf('between %d and %s', $low, self::times($high)),
        };
    }

    /**
     * @param int<0, max> $count
     *
     * @return non-empty-string
     */
    private static function times(int $count): string
    {
        return $count === 1 ? '1 time' : $count . ' times';
    }
}
