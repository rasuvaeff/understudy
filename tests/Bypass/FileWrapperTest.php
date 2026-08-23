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
 * Registering it and letting PHP compile through it is what `BypassFinalsTest`
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

        Assert::false($wrapper->stream_open(__DIR__ . '/nothing-here.php', 'r', 0, $opened));
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

        Assert::false(@$wrapper->dir_opendir(__DIR__ . '/no-such-directory'));
    }

    private function read(string $path): string
    {
        $wrapper = new FileWrapper();
        $opened = null;

        Assert::true($wrapper->stream_open($path, 'r', 0, $opened));

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
}
