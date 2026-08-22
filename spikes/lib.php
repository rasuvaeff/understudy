<?php

declare(strict_types=1);

namespace Understudy\Spikes;

function ok(string $message): void
{
    echo "  ok: {$message}\n";
}

function fail(string $message): never
{
    fwrite(STDERR, "  FAIL: {$message}\n");
    exit(1);
}

function assertSame(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        fail($label . ' — expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }

    ok($label);
}

function assertTrue(bool $condition, string $label): void
{
    $condition ? ok($label) : fail($label);
}

/**
 * @param class-string<\Throwable> $exceptionClass
 */
function expectThrows(callable $callback, string $exceptionClass, string $label): \Throwable
{
    try {
        $callback();
    } catch (\Throwable $e) {
        if (!$e instanceof $exceptionClass) {
            fail($label . ' — expected ' . $exceptionClass . ', got ' . $e::class . ': ' . $e->getMessage());
        }

        ok($label);

        return $e;
    }

    fail($label . ' — nothing thrown');
}
