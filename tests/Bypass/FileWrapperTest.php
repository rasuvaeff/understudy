<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Bypass;

use Rasuvaeff\Understudy\Bypass\FileWrapper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

/**
 * The wrapper's own operations, driven directly.
 *
 * Registering it and letting PHP compile through it is what `BypassFinalsIntegrationTest`
 * does, in a process per scenario — the only way to prove a load-order claim,
 * and invisible to coverage. Everything that is just a method taking a path can
 * be exercised here instead, where a mistake shows up as a failing assertion
 * rather than as a subprocess that quietly answered something else.
 */
#[Test]
#[Covers(FileWrapper::class)]
final class FileWrapperTest
{
    #[AfterTest]
    public function tearDown(): void
    {
        FileWrapper::uninstall();
        FileWrapper::targetOnly([]);
    }

    // --- Registration ---------------------------------------------------------

    public function installingAndRemovingTheWrapperIsVisible(): void
    {
        Assert::false(FileWrapper::isInstalled());

        FileWrapper::install([]);

        Assert::true(FileWrapper::isInstalled());

        FileWrapper::uninstall();

        Assert::false(FileWrapper::isInstalled());
    }

    /**
     * Reading has to keep working while the wrapper is in place — it is the
     * first thing Composer does through it.
     */
    public function anOrdinaryReadStillWorksWhileInstalled(): void
    {
        FileWrapper::install([]);

        $composer = file_get_contents(\dirname(__DIR__, 2) . '/composer.json');

        Assert::true(\is_string($composer));
        Assert::notNull(json_decode($composer, associative: true));
    }

    /**
     * Two calls widen one allow-list. Stacking wrappers instead would recurse
     * on the first read, and replacing the list would silently un-bypass
     * whatever the earlier call asked for.
     */
    public function installingTwiceWidensOneAllowList(): void
    {
        $namespace = 'Rasuvaeff\\Understudy\\Tests\\Fixture\\Bypass';

        FileWrapper::install([['namespace' => $namespace, 'class' => 'OpenedNeighbour']]);
        FileWrapper::install([['namespace' => $namespace, 'class' => 'SealedNeighbour']]);

        $read = $this->read(\dirname(__DIR__) . '/Fixture/Bypass/Neighbours.php');

        // By declaration, not by the phrase: the file's own docblock says
        // "final classes", and a substring assertion would read that as one.
        Assert::false(str_contains($read, 'final class OpenedNeighbour'));
        Assert::false(str_contains($read, 'final class SealedNeighbour'));
        Assert::string($read)->contains('class OpenedNeighbour');
        Assert::string($read)->contains('class SealedNeighbour');
    }

    /**
     * The global mode is not a wider list, it is the absence of one: a later
     * targeted call must not narrow it back down.
     */
    public function theGlobalModeIsNotNarrowedByALaterTargetedCall(): void
    {
        FileWrapper::install(null);
        FileWrapper::install([['namespace' => 'Nowhere', 'class' => 'Nothing']]);

        $read = $this->read(\dirname(__DIR__) . '/Fixture/Bypass/Neighbours.php');

        Assert::false(str_contains($read, 'final class OpenedNeighbour'));
        Assert::false(str_contains($read, 'final class SealedNeighbour'));
    }

    /**
     * A targeted list installed first must survive the switch to global, too:
     * the two calls compose into "everything", not into "the list".
     */
    public function aTargetedListIsWidenedIntoTheGlobalMode(): void
    {
        FileWrapper::install([['namespace' => 'Nowhere', 'class' => 'Nothing']]);
        FileWrapper::install(null);

        $read = $this->read(\dirname(__DIR__) . '/Fixture/Bypass/Neighbours.php');

        Assert::false(str_contains($read, 'final class OpenedNeighbour'));
        Assert::false(str_contains($read, 'final class SealedNeighbour'));
    }

    public function removingAWrapperThatIsNotThereIsHarmless(): void
    {
        FileWrapper::uninstall();

        Assert::false(FileWrapper::isInstalled());
    }

    // --- Reading through it ---------------------------------------------------

    public function aPhpSourceIsHandedOverTransformed(): void
    {
        FileWrapper::targetOnly([['namespace' => 'Rasuvaeff\\Understudy\\Tests\\Fixture\\Bypass', 'class' => 'SealedGate']]);

        $read = $this->read(\dirname(__DIR__) . '/Fixture/Bypass/SealedGate.php');

        Assert::string($read)->contains('class SealedGate');
        Assert::false(str_contains($read, 'final class SealedGate'));
    }

    public function aPhpSourceNobodyAskedAboutIsHandedOverAsItIs(): void
    {
        FileWrapper::targetOnly([['namespace' => 'Other', 'class' => 'Gate']]);

        $read = $this->read(\dirname(__DIR__) . '/Fixture/Bypass/SealedGate.php');

        Assert::string($read)->contains('final class SealedGate');
    }

    public function aFileThatIsNotPhpIsHandedOverAsItIs(): void
    {
        FileWrapper::targetOnly(null);
        $path = \dirname(__DIR__, 2) . '/composer.json';

        Assert::same($this->read($path), (string) file_get_contents($path));
    }

    public function aMissingFileFailsToOpen(): void
    {
        $wrapper = new FileWrapper();
        $opened = null;

        Assert::false($this->quietly(
            static fn(): bool => $wrapper->stream_open(__DIR__ . '/nothing-here.php', 'r', 0, $opened),
        ));
    }

    /**
     * Both targets arrive in one call, so the list has to take all of them —
     * not the first, and not the last.
     */
    public function oneInstallCanNameSeveralClasses(): void
    {
        $namespace = 'Rasuvaeff\\Understudy\\Tests\\Fixture\\Bypass';

        FileWrapper::install([
            ['namespace' => $namespace, 'class' => 'OpenedNeighbour'],
            ['namespace' => $namespace, 'class' => 'SealedNeighbour'],
        ]);

        $read = $this->read(\dirname(__DIR__) . '/Fixture/Bypass/Neighbours.php');

        Assert::false(str_contains($read, 'final class OpenedNeighbour'));
        Assert::false(str_contains($read, 'final class SealedNeighbour'));
    }

    /**
     * Through `file://`, not through an instance this test built: what is under
     * test is that the registration actually took, and reading a file is the
     * only way to see that from outside.
     */
    public function aRegisteredWrapperTransformsWhatPhpItselfReads(): void
    {
        $path = \dirname(__DIR__) . '/Fixture/Bypass/SealedGate.php';

        Assert::string((string) file_get_contents($path))->contains('final class SealedGate');

        FileWrapper::install([[
            'namespace' => 'Rasuvaeff\\Understudy\\Tests\\Fixture\\Bypass',
            'class' => 'SealedGate',
        ]]);

        Assert::false(str_contains((string) file_get_contents($path), 'final class SealedGate'));

        FileWrapper::uninstall();

        Assert::string((string) file_get_contents($path))->contains('final class SealedGate');
    }

    /**
     * A delegated operation borrows the native wrapper and has to give it back.
     * Without that, the first `stat()` would be the last thing transformed.
     */
    public function theWrapperIsStillInPlaceAfterADelegatedOperation(): void
    {
        $path = \dirname(__DIR__) . '/Fixture/Bypass/SealedGate.php';

        FileWrapper::install([[
            'namespace' => 'Rasuvaeff\\Understudy\\Tests\\Fixture\\Bypass',
            'class' => 'SealedGate',
        ]]);

        Assert::true(\is_array(stat($path)));
        Assert::true(\is_array(scandir(\dirname($path))));
        Assert::false(str_contains((string) file_get_contents($path), 'final class SealedGate'));
    }

    // --- What counts as PHP source --------------------------------------------

    public function anUppercaseExtensionIsStillPhp(): void
    {
        FileWrapper::targetOnly(null);

        $read = $this->read(\dirname(__DIR__) . '/Fixture/Bypass/Uppercase.PHP');

        Assert::false(str_contains($read, 'final class Uppercase'));
        Assert::string($read)->contains('class Uppercase');
    }

    /**
     * A read-write handle is not a compile, and rewriting what somebody is
     * about to edit would be a different program than the one on disk.
     */
    public function aReadWriteHandleIsNotTransformed(): void
    {
        FileWrapper::targetOnly(null);

        Assert::string($this->read(\dirname(__DIR__) . '/Fixture/Bypass/SealedGate.php', 'r+'))
            ->contains('final class SealedGate');
    }

    public function aWriteHandleIsNotTransformed(): void
    {
        FileWrapper::targetOnly(null);
        $path = sys_get_temp_dir() . '/understudy-write-' . getmypid() . '.php';
        file_put_contents($path, '<?php final class Written {}');

        try {
            $wrapper = new FileWrapper();
            $opened = null;

            Assert::true($wrapper->stream_open($path, 'a', 0, $opened));

            $wrapper->stream_close();

            Assert::string((string) file_get_contents($path))->contains('final class Written');
        } finally {
            unlink($path);
        }
    }

    /**
     * Reading a file through a wrapper nobody installed must leave `file://`
     * alone.
     *
     * Every delegated operation borrows the native wrapper and puts ours back
     * afterwards — but "back" means *back*, and there is nothing to restore
     * when nothing was registered. Without the guard, one read through a bare
     * instance installs the wrapper for the rest of the process, and rector
     * removed that guard once already by turning the method non-static.
     */
    public function readingThroughAnUninstalledWrapperDoesNotInstallIt(): void
    {
        $path = \dirname(__DIR__) . '/Fixture/Bypass/SealedGate.php';

        FileWrapper::targetOnly(null);
        $this->read($path);

        Assert::false(FileWrapper::isInstalled());
        Assert::string((string) file_get_contents($path))->contains('final class SealedGate');
    }

    // --- The rest of the stream protocol --------------------------------------

    public function theStreamReportsPositionSizeAndEnd(): void
    {
        FileWrapper::targetOnly([]);
        $wrapper = new FileWrapper();
        $opened = null;
        $path = \dirname(__DIR__) . '/Fixture/Bypass/SealedGate.php';

        Assert::true($wrapper->stream_open($path, 'r', STREAM_USE_PATH, $opened));
        Assert::same($opened, $path);
        Assert::same($wrapper->stream_tell(), 0);
        Assert::false($wrapper->stream_eof());

        $first = $wrapper->stream_read(5);

        Assert::same($first, '<?php');
        Assert::same($wrapper->stream_tell(), 5);
        Assert::true($wrapper->stream_seek(0));
        Assert::same($wrapper->stream_tell(), 0);

        $stat = $wrapper->stream_stat();

        Assert::true(\is_array($stat));
        Assert::true($wrapper->stream_flush());
        // Nothing here changes blocking or timeouts, and saying otherwise would
        // have callers believe a setting took effect.
        Assert::false($wrapper->stream_set_option());

        $wrapper->stream_close();
    }

    public function lockingTruncatingAndCastingDelegateToNativeStream(): void
    {
        $path = sys_get_temp_dir() . '/understudy-stream-' . getmypid() . '.txt';
        file_put_contents($path, 'abcdef');

        try {
            FileWrapper::install([]);
            $handle = fopen($path, 'r+');
            Assert::true(is_resource($handle));
            Assert::true(flock($handle, LOCK_EX));
            Assert::true(ftruncate($handle, 3));
            rewind($handle);
            Assert::same((string) stream_get_contents($handle), 'abc');

            if (DIRECTORY_SEPARATOR !== '\\') {
                $read = [$handle];
                $write = null;
                $except = null;
                Assert::same(stream_select($read, $write, $except, 0), 1);
            }

            flock($handle, LOCK_UN);
            fclose($handle);
        } finally {
            FileWrapper::uninstall();
            @unlink($path);
        }
    }

    public function lockedFilePutContentsStillWrites(): void
    {
        $path = sys_get_temp_dir() . '/understudy-locked-write-' . getmypid() . '.txt';

        try {
            FileWrapper::install([]);

            Assert::same(file_put_contents($path, 'xx', LOCK_EX), 2);
            Assert::same((string) file_get_contents($path), 'xx');
        } finally {
            FileWrapper::uninstall();
            @unlink($path);
        }
    }

    public function statAnswersForTheRealPath(): void
    {
        $wrapper = new FileWrapper();
        $path = \dirname(__DIR__) . '/Fixture/Bypass/SealedGate.php';

        $stat = $wrapper->url_stat($path, 0);

        Assert::true(\is_array($stat));
        Assert::same($stat['size'], filesize($path));
        Assert::false($wrapper->url_stat($path . '.missing', STREAM_URL_STAT_QUIET));
    }

    /**
     * `STREAM_URL_STAT_LINK` asks about the link, not what it points at —
     * `is_link()` on the answer is the only way to tell the two calls apart.
     */
    public function statFollowsOrDoesNotFollowALinkAsAsked(): void
    {
        $wrapper = new FileWrapper();
        $target = \dirname(__DIR__) . '/Fixture/Bypass/SealedGate.php';
        $link = sys_get_temp_dir() . '/understudy-link-' . getmypid() . '.php';

        if (file_exists($link)) {
            unlink($link);
        }

        if (!@symlink($target, $link)) {
            // Windows needs a privilege for this that CI does not have. The
            // flag still has to reach `stat()`, and on a plain file both calls
            // answer the same thing — which is the part that can be checked
            // anywhere.
            $plain = $wrapper->url_stat($target, STREAM_URL_STAT_LINK);

            Assert::true(\is_array($plain));
            Assert::same($plain['size'], filesize($target));

            return;
        }

        try {
            $followed = $wrapper->url_stat($link, 0);
            $notFollowed = $wrapper->url_stat($link, STREAM_URL_STAT_LINK);

            Assert::true(\is_array($followed));
            Assert::true(\is_array($notFollowed));
            Assert::same($followed['size'], filesize($target));
            Assert::true($notFollowed['size'] !== $followed['size']);
        } finally {
            unlink($link);
        }
    }

    public function directoriesAreListedThroughTheNativeWrapper(): void
    {
        $wrapper = new FileWrapper();
        $directory = \dirname(__DIR__) . '/Fixture/Bypass';

        Assert::true($wrapper->dir_opendir($directory));

        $entries = [];

        while (($entry = $wrapper->dir_readdir()) !== false) {
            $entries[] = $entry;
        }

        Assert::true($wrapper->dir_rewinddir());
        Assert::true($wrapper->dir_closedir());
        Assert::true(\in_array('SealedGate.php', $entries, strict: true));
    }

    public function openingAMissingDirectoryFails(): void
    {
        $wrapper = new FileWrapper();

        Assert::false($this->quietly(
            static fn(): bool => $wrapper->dir_opendir(__DIR__ . '/no-such-directory'),
        ));
    }

    /**
     * Runs one call with diagnostics swallowed.
     *
     * What is under test here is a return value, not whether PHP said anything
     * on the way. `@` is not enough: a handler that does not consult
     * `error_reporting()` fires through it, and Infection installs one — a
     * single line on STDERR makes it abandon the whole run. Since any mutant of
     * the code being called may be the one that talks, the silence has to be
     * imposed from out here.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function quietly(callable $call): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    private function read(string $path, string $mode = 'r'): string
    {
        $wrapper = new FileWrapper();
        $opened = null;

        Assert::true($wrapper->stream_open($path, $mode, 0, $opened));
        // Not asked for, so not answered: `$openedPath` is what `__FILE__`
        // becomes, and PHP only wants it when STREAM_USE_PATH is set.
        Assert::null($opened);

        $read = '';

        while (!$wrapper->stream_eof()) {
            $chunk = $wrapper->stream_read(8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $read .= $chunk;
        }

        $wrapper->stream_close();

        return $read;
    }
    // --- The allow-list itself -------------------------------------------------

    public function theAllowListIsMatchedByNamespaceAndShortNameTogether(): void
    {
        FileWrapper::targetOnly([['namespace' => 'App\\Domain', 'class' => 'Order']]);

        Assert::true(FileWrapper::covers('App\\Domain\\Order'));
        // Same short name, another namespace: a bypass asked for one class
        // must not quietly rewrite its namesake somewhere else.
        Assert::false(FileWrapper::covers('App\\Billing\\Order'));
        // Same namespace, another class.
        Assert::false(FileWrapper::covers('App\\Domain\\Invoice'));
    }

    public function aGlobalNamespaceClassIsMatchedByAnEmptyNamespace(): void
    {
        FileWrapper::targetOnly([['namespace' => '', 'class' => 'Order']]);

        Assert::true(FileWrapper::covers('Order'));
        Assert::false(FileWrapper::covers('App\\Order'));
    }

    public function theGlobalModeCoversEveryClass(): void
    {
        FileWrapper::targetOnly(null);

        Assert::true(FileWrapper::covers('Anything\\At\\All'));
    }

    public function anEmptyAllowListCoversNothing(): void
    {
        FileWrapper::targetOnly([]);

        Assert::false(FileWrapper::covers('Anything\\At\\All'));
    }

    // --- Filesystem operations pass straight through ---------------------------

    public function creatingRenamingAndRemovingFilesGoesThroughUntouched(): void
    {
        // Everything that is not a read of PHP source is the native wrapper's
        // job. A wrapper that answered only what it cared about would break
        // Composer, which is the first thing that runs through it.
        $directory = $this->temporaryDirectory();
        FileWrapper::install(null);

        $nested = $directory . '/nested';
        Assert::true(mkdir($nested));
        Assert::true(is_dir($nested));

        $first = $nested . '/first.txt';
        file_put_contents($first, 'content');
        Assert::same(file_get_contents($first), 'content');

        $second = $nested . '/second.txt';
        Assert::true(rename($first, $second));
        Assert::false(file_exists($first));
        Assert::same(file_get_contents($second), 'content');

        Assert::true(unlink($second));
        Assert::false(file_exists($second));

        Assert::true(rmdir($nested));
        Assert::false(is_dir($nested));

        self::removeDirectory($directory);
    }

    public function touchingAndChmoddingAFileGoesThroughUntouched(): void
    {
        $directory = $this->temporaryDirectory();
        $path = $directory . '/stamped.txt';
        file_put_contents($path, 'x');

        FileWrapper::install(null);

        Assert::true(touch($path));
        Assert::true(touch($path, 1_000_000_000, 1_000_000_001));
        clearstatcache(clear_realpath_cache: true, filename: $path);
        Assert::same(filemtime($path), 1_000_000_000);
        Assert::same(fileatime($path), 1_000_000_001);

        // Asserted against a control rather than against a literal: Windows
        // has no Unix permission bits, so `chmod()` there toggles read-only
        // and `fileperms()` answers 0666 whatever was asked for. The claim is
        // not "the mode is 0600" — it is "the wrapper changed nothing", and
        // the only portable way to say that is to do the same chmod without
        // it and compare.
        $control = $directory . '/control.txt';
        file_put_contents($control, 'x');

        Assert::true(chmod($path, 0o600));

        FileWrapper::uninstall();

        Assert::true(chmod($control, 0o600));
        clearstatcache(clear_realpath_cache: true, filename: $path);
        clearstatcache(clear_realpath_cache: true, filename: $control);

        Assert::same(fileperms($path) & 0o777, fileperms($control) & 0o777);

        self::removeDirectory($directory);
    }

    public function creatingAFileByOpeningItForWritingWorks(): void
    {
        $directory = $this->temporaryDirectory();
        FileWrapper::install(null);

        $path = $directory . '/made.txt';
        $handle = fopen($path, 'w');
        Assert::true($handle !== false);
        fwrite($handle, 'plain content');
        fclose($handle);

        Assert::same(file_get_contents($path), 'plain content');

        self::removeDirectory($directory);
    }

    public function inGlobalModeEvenAPlainReadOfPhpSourceComesBackTransformed(): void
    {
        // Not a side effect to be surprised by later: the wrapper transforms
        // what is READ through `file://`, and PHP compiling a class is only
        // the most important reader. Anything else reading PHP source in the
        // same process — a template engine, a generator, an in-process linter
        // — sees the transformed text too, for as long as bypass is on.
        $directory = $this->temporaryDirectory();
        $path = $directory . '/sealed.php';
        file_put_contents($path, '<?php final class SealedOnDisk {}');

        FileWrapper::install(null);

        Assert::same(file_get_contents($path), '<?php class SealedOnDisk {}');

        self::removeDirectory($directory);

        // On disk the source never changed; only the reading did.
        Assert::false(file_exists($path));
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/understudy-wrapper-' . bin2hex(random_bytes(6));
        mkdir($directory);

        return $directory;
    }

    private static function removeDirectory(string $path): void
    {
        FileWrapper::uninstall();

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_string($entry)) {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? self::removeDirectory($full) : unlink($full);
        }

        rmdir($path);
    }

}
