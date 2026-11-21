<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy;

use Rasuvaeff\Understudy\Expectation\ArgumentFormatter;
use Rasuvaeff\Understudy\Expectation\Expectation;
use Rasuvaeff\Understudy\Matcher\ArgumentMatcher;
use Rasuvaeff\Understudy\Runtime\ArmedSequence;

/**
 * Renders why a verification failed: what was expected, what happened, and —
 * where the method was called but with other arguments — which argument
 * differed, marked with asterisks.
 *
 * @internal
 */
final class FailureReport
{
    /** A wall of stubs tells the reader less than a count does. */
    private const int MAX_CANDIDATES = 5;

    private function __construct() {}

    /**
     * @param non-empty-string  $label
     * @param non-empty-string  $expectation
     * @param int<0, max>       $actual
     * @param list<Invocation>  $callLog
     * @param non-empty-string  $method
     * @param list<mixed>       $expectedArgs
     *
     * @return non-empty-string
     */
    public static function render(
        string $label,
        string $expectation,
        Cardinality $cardinality,
        int $actual,
        array $callLog,
        string $method,
        array $expectedArgs,
    ): string {
        $report = sprintf(
            "Understudy `%s` expected `%s` to be called %s, but %s.",
            $label,
            $expectation,
            $cardinality->describe(),
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
        return ArgumentFormatter::scope(
            static function () use ($invocation): string {
                $arguments = [];

                /** @var mixed $argument */
                foreach ($invocation->args as $position => $argument) {
                    $arguments[] = self::renderArgument($invocation, $position, $argument);
                }

                return $invocation->method . '(' . implode(', ', $arguments) . ')';
            },
        );
    }

    /**
     * One recorded argument, redacted where the contract asked for it.
     *
     * PHP redacts a `#[\SensitiveParameter]` in its own stack traces;
     * understudy formats arguments itself and printed the value verbatim —
     * into the failure message and into `transcript()`, which is to say into
     * a CI log. The placeholder is PHP's own wording, so a reader who has
     * seen a redacted trace recognises it.
     *
     * The type is kept: knowing a password argument was a string and not null
     * is most of what the message is read for, and it gives nothing away.
     */
    private static function renderArgument(Invocation $invocation, int $position, mixed $argument): string
    {
        if (!\in_array($position, $invocation->sensitiveArguments, strict: true)) {
            return ArgumentFormatter::format($argument);
        }

        return sprintf('%s SensitiveParameter', get_debug_type($argument));
    }

    /**
     * The call a strict double refused, and what was configured for that
     * method and did not accept it — with the arguments that rejected it
     * marked, read from the expectation's side.
     *
     * One alias table for both halves: the whole value of the comparison is
     * that the reader can tell the object in the call from the one in a stub.
     *
     * @param list<Expectation> $candidates in the order the dispatcher tried them
     *
     * @return non-empty-string
     */
    public static function renderRefusal(Invocation $invocation, array $candidates): string
    {
        return ArgumentFormatter::scope(static function () use ($invocation, $candidates): string {
            // The call is rendered first so that it holds the first alias: it
            // is the thing the reader is being told about, and a message whose
            // subject is `Book#2` reads like the answer to another question.
            $call = self::renderCall($invocation);

            $lines = array_map(
                static fn(Expectation $candidate): string
                    => '    ' . $candidate->describeAgainst($invocation->args),
                array_slice($candidates, 0, self::MAX_CANDIDATES),
            );

            $hidden = count($candidates) - count($lines);

            if ($hidden > 0) {
                $lines[] = sprintf('    … and %d more', $hidden);
            }

            return sprintf(
                "The call was:\n    %s\n\nNothing configured for `%s` accepted it:\n%s",
                $call,
                $invocation->method,
                implode("\n", $lines),
            );
        });
    }

    /**
     * @param list<Invocation> $callLog
     * @param list<mixed>      $expectedArgs marked arguments are the ones that differed
     */
    public static function renderCallLog(array $callLog, array $expectedArgs = []): string
    {
        return ArgumentFormatter::scope(static function () use ($callLog, $expectedArgs): string {
            $lines = [];

            foreach ($callLog as $invocation) {
                $arguments = [];

                /** @var mixed $argument */
                foreach ($invocation->args as $position => $argument) {
                    $rendered = self::renderArgument($invocation, $position, $argument);
                    $arguments[] = self::differs($expectedArgs, $position, $argument)
                        ? '*' . $rendered . '*'
                        : $rendered;
                }

                $lines[] = sprintf('    %s(%s)', $invocation->method, implode(', ', $arguments));
            }

            return implode("\n", $lines);
        });
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

        // Guarded as `Expectation::accepts()` is: a predicate the dispatcher
        // never ran on this call (the first-literal index skipped it) must not
        // replace the failure being rendered with its own exception.
        try {
            return $expected instanceof ArgumentMatcher
                ? !$expected->matches($actual)
                : $expected !== $actual;
        } catch (\Throwable) {
            return true;
        }
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

    /**
     * One rendering for both refusals: the protocol as a numbered list with
     * the step due marked, and the call that arrived. One alias table over all
     * of it, so an object in the step is comparable with the one in the call.
     *
     * @return non-empty-string
     */
    private static function describeProtocol(ArmedSequence $sequence, Invocation $invocation, object $double): string
    {
        return ArgumentFormatter::scope(static function () use ($sequence, $invocation, $double): string {
            $call = self::renderCall($invocation);
            $elsewhere = $sequence->stepsOwnedElsewhere($double);
            $lines = [];

            foreach ($sequence->describe() as $index => $step) {
                $lines[] = sprintf(
                    '    %d. %s%s%s',
                    $index + 1,
                    $step,
                    ($elsewhere[$index] ?? false) ? '   (on another understudy)' : '',
                    $index + 1 === $sequence->position() ? '   <- due here' : '',
                );
            }

            return sprintf("The call was:\n    %s\n\nThe protocol is:\n%s", $call, implode("\n", $lines));
        });
    }

    /**
     * The refusal for a protocol step that arrived out of turn.
     *
     * @param non-empty-string $label
     */
    public static function outOfTurn(string $label, ArmedSequence $sequence, Invocation $invocation, object $double): VerificationFailure
    {
        // The step due can read exactly like the call that arrived, because a
        // protocol spanning two doubles renders every step by its call alone.
        // Saying whose step it was is the difference between a report and a
        // riddle.
        $elsewhere = $sequence->pendingOwner() !== null && $sequence->pendingOwner() !== $double
            ? ' on another understudy'
            : '';

        return ArgumentFormatter::scope(static fn(): VerificationFailure => new VerificationFailure(
            kind: FailureKind::OutOfSequence,
            summary: sprintf(
                "Understudy `%s` received a protocol call out of turn: step %d of %d was expected to be `%s`%s.\n\n%s",
                $label,
                $sequence->position(),
                $sequence->length(),
                $sequence->pending()?->describe() ?? 'nothing — the protocol has run out',
                $elsewhere,
                self::describeProtocol($sequence, $invocation, $double),
            ),
            double: $label,
            expectation: $sequence->pending()?->describe(),
            observedCalls: [$invocation],
            expectedCalls: $sequence->describe(),
        ));
    }

    /**
     * The refusal for a call on a double under protocol that is neither a step
     * nor configured.
     *
     * @param non-empty-string $label
     */
    public static function unconfiguredUnderProtocol(string $label, ArmedSequence $sequence, Invocation $invocation, object $double): VerificationFailure
    {
        return ArgumentFormatter::scope(static fn(): VerificationFailure => new VerificationFailure(
            kind: FailureKind::OutOfSequence,
            summary: sprintf(
                "Understudy `%s` is under an armed protocol and received a call that is neither a step nor configured.\n\n%s\n\n"
                . 'Say it may happen — when(fn () => $double->%s(...))->returns(...) — or make it a step.',
                $label,
                self::describeProtocol($sequence, $invocation, $double),
                $invocation->method,
            ),
            double: $label,
            expectation: $sequence->pending()?->describe(),
            observedCalls: [$invocation],
            expectedCalls: $sequence->describe(),
        ));
    }
}
