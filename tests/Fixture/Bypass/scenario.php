<?php

declare(strict_types=1);

/**
 * One scenario, one process. Started by `BypassFinalsIntegrationTest`, never on its own.
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

    // --- The environment the process runs in --------------------------------
    'line-numbers-preserved' => (static function (): string {
        Understudy::bypassFinals(LineReporter::class);
        $double = Understudy::for(LineReporter::class);

        $opened = !(new \ReflectionClass(LineReporter::class))->isFinal();

        try {
            (new LineReporter())->fail();

            return 'it did not throw';
        } catch (\RuntimeException $thrown) {
            return $opened && $double instanceof LineReporter && $thrown->getLine() === LineReporter::THROW_LINE
                ? 'opened, line ' . LineReporter::THROW_LINE
                : 'opened=' . var_export($opened, true) . ', line ' . $thrown->getLine();
        }
    })(),

    'opcache-warm' => (static function (): string {
        Understudy::bypassFinals(SealedGate::class);
        $double = Understudy::for(SealedGate::class);

        // The claim is about behaviour, not about what the cache holds. Whether
        // a file read through a userland wrapper is cached at all turns out to
        // differ between platforms — Linux keeps it out, Windows does not — so
        // asserting on that would be asserting on the implementation of an
        // opcode cache. What has to hold either way is this: the class is open
        // and the double works, no matter how warm the cache was.
        $status = \function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $enabled = \is_array($status) && ($status['opcache_enabled'] ?? false) === true;

        return ($double instanceof SealedGate ? 'doubled' : 'not a SealedGate')
            . ', ' . ((new \ReflectionClass(SealedGate::class))->isFinal() ? 'resealed' : 'still open')
            . ', opcache ' . ($enabled ? 'on' : 'off');
    })(),

    'preloaded' => (static function (): string {
        // Preloading runs before any bootstrap, so the class is in memory
        // before bypass could have been asked for. There is nothing left to
        // lift, and saying so is the whole of the support for preloading.
        if (!class_exists(SealedGate::class, autoload: false)) {
            return 'not preloaded';
        }

        try {
            Understudy::bypassFinals(SealedGate::class);

            return 'accepted a preloaded class';
        } catch (BypassUnavailable $refused) {
            return str_contains($refused->getMessage(), 'is already loaded')
                ? 'refused'
                : 'refused with: ' . $refused->getMessage();
        }
    })(),

    'phar' => (static function (): string {
        if (\ini_get('phar.readonly') !== '0') {
            return 'skipped: phar.readonly is on';
        }

        $archive = sys_get_temp_dir() . '/understudy-bypass-' . getmypid() . '.phar';
        @unlink($archive);

        $phar = new \Phar($archive);
        $phar->addFromString(
            'Locked.php',
            "<?php\nnamespace PharFixture;\nfinal class Locked { public function value(): int { return 7; } }\n",
        );
        $phar->setStub("<?php Phar::mapPhar(); __HALT_COMPILER();");

        Understudy::bypassFinals(\PharFixture\Locked::class);
        require 'phar://' . $archive . '/Locked.php';
        @unlink($archive);

        try {
            Understudy::for(\PharFixture\Locked::class);

            return 'doubled a class out of a PHAR';
        } catch (UnsupportedTarget $refused) {
            return str_contains($refused->getMessage(), 'could not reach it')
                && str_contains($refused->getMessage(), 'read out of a PHAR')
                    ? 'refused, naming the PHAR'
                    : 'refused with: ' . $refused->getMessage();
        }
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

    'foreign-stripper' => (static function (): string {
        // Everything the refusal itself needs has to be in memory first: it
        // reads this package's own source back, and reading it through the
        // other wrapper is the point of the test, not a way to break it.
        class_exists(\Rasuvaeff\Understudy\Bypass\FinalStripper::class);
        ForeignStripperWrapper::install();

        try {
            Understudy::bypassFinals(SealedGate::class);

            return 'installed over another stripper';
        } catch (BypassUnavailable $refused) {
            return str_contains($refused->getMessage(), 'the source it read back was not the source on disk')
                ? 'refused'
                : 'refused with: ' . $refused->getMessage();
        } finally {
            stream_wrapper_restore('file');
        }
    })(),

    'passive-wrapper' => (static function (): string {
        class_exists(\Rasuvaeff\Understudy\Bypass\FinalStripper::class);
        PassiveWrapper::install();

        try {
            Understudy::bypassFinals(SealedGate::class);

            return 'accepted';
        } catch (BypassUnavailable $refused) {
            return 'refused: ' . $refused->getMessage();
        } finally {
            stream_wrapper_restore('file');
        }
    })(),

    'bypass-for-another-class' => (static function (): string {
        Understudy::bypassFinals(SealedValue::class);

        try {
            Understudy::for(SealedGate::class);

            return 'doubled a class nobody asked to open';
        } catch (UnsupportedTarget $refused) {
            return str_contains($refused->getMessage(), 'enabled for other classes but not this one')
                ? 'refused, naming the omission'
                : 'refused with: ' . $refused->getMessage();
        }
    })(),

    default => 'unknown scenario',
}, "\n";
