<?php

declare(strict_types=1);

/**
 * One scenario, one process. Started by `BypassFinalsTest`, never on its own.
 *
 * A class is read from disk once per process, so every claim about lifting
 * `final` before that read has to be made in a process of its own. Running them
 * in one would prove only whichever ran first.
 */

namespace Rasuvaeff\Understudy\Tests\Fixture\Bypass;

use Rasuvaeff\Understudy\Exception\BypassUnavailable;
use Rasuvaeff\Understudy\Exception\UnsupportedTarget;
use Rasuvaeff\Understudy\Understudy;

require \dirname(__DIR__, 3) . '/vendor/autoload.php';

$fixtures = __DIR__;

$scenario = $argv[1] ?? '';

echo match ($scenario) {
    // --- The mechanism ----------------------------------------------------
    'targeted' => (static function (): string {
        Understudy::bypassFinals(SealedGate::class);
        $double = Understudy::for(SealedGate::class);

        return $double instanceof SealedGate ? 'doubled' : 'not a SealedGate';
    })(),

    'without-bypass' => (static function (): string {
        try {
            Understudy::for(SealedGate::class);

            return 'built one anyway';
        } catch (UnsupportedTarget $refused) {
            return str_contains($refused->getMessage(), 'the class is final, and bypass is not enabled')
                && str_contains($refused->getMessage(), 'Understudy::bypassFinals(SealedGate::class)')
                    ? 'refused with the recipe'
                    : 'refused with: ' . $refused->getMessage();
        }
    })(),

    // --- Reach ------------------------------------------------------------
    'sibling-untouched' => (static function () use ($fixtures): string {
        Understudy::bypassFinals(OpenedNeighbour::class);
        require $fixtures . '/Neighbours.php';

        $opened = (new \ReflectionClass(OpenedNeighbour::class))->isFinal() ? 'still final' : 'opened';
        $sealed = (new \ReflectionClass(SealedNeighbour::class))->isFinal() ? 'still final' : 'opened';

        return $opened . '/' . $sealed;
    })(),

    'global' => (static function () use ($fixtures): string {
        Understudy::bypassFinals();
        require $fixtures . '/Neighbours.php';

        return ((new \ReflectionClass(OpenedNeighbour::class))->isFinal() ? 'final' : 'opened')
            . '/' . ((new \ReflectionClass(SealedNeighbour::class))->isFinal() ? 'final' : 'opened');
    })(),

    'final-method-survives' => (static function (): string {
        Understudy::bypassFinals(SealedWithFinalMethod::class);

        $class = new \ReflectionClass(SealedWithFinalMethod::class);
        $classOpened = !$class->isFinal();
        $methodStillFinal = $class->getMethod('locked')->isFinal();

        try {
            Understudy::for(SealedWithFinalMethod::class);

            return 'the final method was doubled';
        } catch (UnsupportedTarget) {
            return $classOpened && $methodStillFinal ? 'class opened, method sealed, target refused' : 'unexpected';
        }
    })(),

    'final-readonly' => (static function (): string {
        Understudy::bypassFinals(SealedValue::class);
        $double = Understudy::for(SealedValue::class);

        return $double instanceof SealedValue && (new \ReflectionClass($double))->isReadOnly()
            ? 'readonly double'
            : 'not a readonly double';
    })(),

    // --- What the file still thinks it is ----------------------------------
    'paths-preserved' => (static function () use ($fixtures): string {
        Understudy::bypassFinals(PathReporter::class);
        require $fixtures . '/PathReporter.php';

        // Separators are normalised because Windows reports `__FILE__` with
        // backslashes: what is under test is which file was compiled, not how
        // the platform spells the path to it.
        $slashes = static fn(string $path): string => str_replace('\\', '/', $path);

        return $slashes(PathReporter::file()) === $slashes($fixtures) . '/PathReporter.php'
            && $slashes(PathReporter::directory()) === $slashes($fixtures)
            && PathReporter::included() === 'relative include resolved'
                ? 'paths intact'
                : 'paths changed';
    })(),

    'non-php-untouched' => (static function () use ($fixtures): string {
        Understudy::bypassFinals(SealedGate::class);
        $composer = file_get_contents(\dirname($fixtures, 3) . '/composer.json');

        return \is_string($composer) && json_decode($composer, true) !== null
            ? 'json intact'
            : 'json broken';
    })(),

    // --- Refusals ----------------------------------------------------------
    'already-loaded' => (static function (): string {
        class_exists(SealedGate::class);

        try {
            Understudy::bypassFinals(SealedGate::class);

            return 'accepted a loaded class';
        } catch (BypassUnavailable $refused) {
            return str_contains($refused->getMessage(), 'is already loaded')
                ? 'refused'
                : 'refused with: ' . $refused->getMessage();
        }
    })(),

    'enum' => (static function (): string {
        // Loaded first on purpose: `Suit::class` is a constant expression and
        // autoloads nothing, and an unloaded name cannot be classified without
        // loading it — which would defeat the bypass it was asked about.
        enum_exists(\Rasuvaeff\Understudy\Tests\Fixture\Suit::class);

        try {
            Understudy::bypassFinals(\Rasuvaeff\Understudy\Tests\Fixture\Suit::class);

            return 'accepted an enum';
        } catch (BypassUnavailable $refused) {
            return str_contains($refused->getMessage(), 'is an enum') ? 'refused' : 'refused with: ' . $refused->getMessage();
        }
    })(),

    'interface' => (static function (): string {
        interface_exists(\Rasuvaeff\Understudy\Tests\Fixture\Clock::class);

        try {
            Understudy::bypassFinals(\Rasuvaeff\Understudy\Tests\Fixture\Clock::class);

            return 'accepted an interface';
        } catch (BypassUnavailable $refused) {
            return str_contains($refused->getMessage(), 'is an interface') ? 'refused' : 'refused with: ' . $refused->getMessage();
        }
    })(),

    default => 'unknown scenario',
}, "\n";
