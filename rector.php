<?php

declare(strict_types=1);

use Rasuvaeff\RectorNamedLiterals\AddNameToLiteralArgumentRector;
use Rector\Config\RectorConfig;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withRules([AddNameToLiteralArgumentRector::class])
    ->withSkip([
        // Mirrors rasuvaeff/bulkhead and rasuvaeff/circuit-breaker: `@var
        // mixed` is load-bearing at an untyped boundary, and here the boundary
        // is the whole point — a double's arguments and answers are genuinely
        // `mixed`, so Psalm's MixedAssignment has to be answered with a type,
        // not silenced.
        RemoveUselessVarTagRector::class,
        // `$x === null` reads clearer than `!$x instanceof FQCN` in guards.
        FlipTypeControlToUseExclusiveTypeRector::class,
        // The test suite drives generated classes through Reflection, so the
        // dead-code rules cannot see the call sites of fixture members.
        RemoveUnusedPrivateMethodRector::class => [__DIR__ . '/tests'],
        RemoveEmptyClassMethodRector::class => [__DIR__ . '/tests'],
        RemoveUnusedPublicMethodParameterRector::class => [__DIR__ . '/tests'],
    ]);
