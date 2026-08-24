<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests;

use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * Every claim about lifting `final` is made in a process of its own.
 *
 * A class is read from disk once per process and the transform happens as it is
 * read, so two scenarios in one process would prove only whichever ran first —
 * and coverage cannot see into a subprocess either, which is why
 * {@see Bypass\FinalStripperTest} exists beside this: the decisions are unit
 * tested, the end-to-end claims are made here.
 */
#[Test]
#[CoversNothing]
final class BypassFinalsTest
{
    /**
     * @param list<string> $ini
     */
    #[DataProvider('scenarioProvider')]
    public function aScenarioAnswersAsExpected(string $scenario, string $expected, array $ini = []): void
    {
        Assert::same($this->run($scenario, $ini), $expected);
    }

    /**
     * @return iterable<string, array{string, string}|array{string, string, list<string>}>
     */
    public static function scenarioProvider(): iterable
    {
        yield 'a bypassed final class can be doubled' => ['targeted', 'doubled'];
        yield 'without bypass the refusal names the recipe' => ['without-bypass', 'refused with the recipe'];
        yield 'only the named class in a file is opened' => ['sibling-untouched', 'opened/still final'];
        yield 'global mode opens every class in a file' => ['global', 'opened/opened'];
        yield 'a final method survives and still refuses the target' => [
            'final-method-survives',
            'class opened, method sealed, target refused',
        ];
        yield 'final readonly stays readonly' => ['final-readonly', 'readonly double'];
        yield '__FILE__, __DIR__ and a relative include are unchanged' => ['paths-preserved', 'paths intact'];
        yield 'a file that is not PHP passes through untouched' => ['non-php-untouched', 'json intact'];
        yield 'a class already loaded is refused' => ['already-loaded', 'refused'];
        yield 'an enum is refused' => ['enum', 'refused'];
        yield 'an interface is refused' => ['interface', 'refused'];

        // --- The environment the process runs in ---------------------------
        yield 'stripping final does not move a line' => [
            'line-numbers-preserved',
            'opened, line 19',
        ];
        yield 'a second final-stripper on file:// is refused' => ['foreign-stripper', 'refused'];
        // Deliberately the other way round: the refusal asks whether the
        // source read back is the source on disk, not whether anyone else is
        // there. A wrapper that leaves PHP source alone composes.
        yield 'a wrapper that leaves source alone is accepted' => ['passive-wrapper', 'accepted'];
        yield 'a class nobody named stays final, and the refusal says so' => [
            'bypass-for-another-class',
            'refused, naming the omission',
        ];
        yield 'a class read out of a PHAR is out of reach' => [
            'phar',
            'refused, naming the PHAR',
            ['phar.readonly=0'],
        ];
        yield 'a warm opcode cache does not reseal the class' => [
            'opcache-warm',
            'doubled, still open, opcache on',
            ['opcache.enable_cli=1'],
        ];
    }

    /**
     * Preloading runs before any bootstrap could ask for bypass, so the class
     * is in memory already and the refusal says exactly that.
     *
     * Windows makes the same point from the other side: PHP refuses
     * `opcache.preload` at startup, so there is no process to make the claim
     * in. That is asserted rather than skipped — a scenario quietly not run is
     * indistinguishable from one that passed.
     */
    public function aPreloadedClassIsAlreadyLoaded(): void
    {
        $ini = [
            'opcache.enable_cli=1',
            'opcache.preload=' . __DIR__ . '/Fixture/Bypass/preload.php',
        ];

        if (\DIRECTORY_SEPARATOR === '\\') {
            Assert::string($this->execute('preloaded', $ini)['output'])
                ->contains('Preloading is not supported on Windows');

            return;
        }

        Assert::same($this->run('preloaded', $ini), 'refused');
    }

    /**
     * The claim a single process cannot make: that a *warm* cache, filled by an
     * earlier process, still cannot hand PHP the sealed original.
     */
    public function aWarmOpcodeCacheDoesNotResealTheClass(): void
    {
        $cache = sys_get_temp_dir() . '/understudy-opcache-' . getmypid();

        // OPcache refuses to start at all when the directory is not already
        // there, and refusing to start would make both runs pass for the wrong
        // reason.
        if (!is_dir($cache) && !mkdir($cache, 0o777, recursive: true) && !is_dir($cache)) {
            Assert::fail('could not create ' . $cache);
        }

        $ini = [
            'opcache.enable_cli=1',
            'opcache.file_cache=' . $cache,
            'opcache.validate_timestamps=0',
        ];

        $expected = 'doubled, still open, opcache on';

        try {
            Assert::same($this->run('opcache-warm', $ini), $expected, 'cold cache');
            Assert::same($this->run('opcache-warm', $ini), $expected, 'warm cache');
        } finally {
            $this->removeDirectory($cache);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($path);
    }

    /**
     * @param list<string> $ini
     */
    private function run(string $scenario, array $ini = []): string
    {
        ['status' => $status, 'output' => $output] = $this->execute($scenario, $ini);

        Assert::same($status, 0, 'the scenario process exited with ' . $status . ': ' . $output);

        // A scenario answers in one line, but the engine may have said
        // something first — a coverage driver makes PHP warn that JIT is off,
        // on stdout, before anything of ours runs. The answer is the last
        // line; everything above it is already in the failure message when the
        // process exits non-zero.
        $lines = array_values(array_filter(
            array_map(trim(...), explode("\n", $output)),
            static fn(string $line): bool => $line !== '',
        ));

        return $lines === [] ? '' : $lines[array_key_last($lines)];
    }

    /**
     * @param list<string> $ini
     *
     * @return array{status: int, output: string}
     */
    private function execute(string $scenario, array $ini): array
    {
        $flags = '';

        foreach ($ini as $setting) {
            // `escapeshellarg()` quotes with `'` on every platform, and cmd.exe
            // does not know that quote: the setting arrives mangled and PHP
            // reports a syntax error from a line nobody wrote.
            $flags .= '-d ' . (\DIRECTORY_SEPARATOR === '\\' ? '"' . $setting . '"' : escapeshellarg($setting)) . ' ';
        }

        $command = sprintf(
            '%s %s%s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            $flags,
            escapeshellarg(__DIR__ . '/Fixture/Bypass/scenario.php'),
            escapeshellarg($scenario),
        );

        $output = [];
        exec($command, $output, $status);

        return ['status' => $status, 'output' => implode("\n", $output)];
    }
}
