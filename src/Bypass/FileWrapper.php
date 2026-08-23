<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Bypass;

/**
 * A `file://` stream wrapper that hands PHP a transformed copy of the source it
 * is about to compile, and the original bytes of everything else.
 *
 * A temporary copy on disk would have been simpler and wrong: `__FILE__`,
 * `__DIR__`, relative `include`s and PSR-4 resolution all read the path, and a
 * file compiled from `/tmp` answers differently than the one the project
 * shipped. The path stays; only what is read through it changes.
 *
 * Every operation that is not a read of a PHP file is delegated to the native
 * wrapper untouched — `stat`, directory listings, writes, `unlink`. A wrapper
 * that answered only what it cared about would break Composer's own file
 * handling, which is the first thing that runs through it.
 *
 * @internal
 */
final class FileWrapper
{
    public const string PROTOCOL = 'file';

    /** Set by PHP on every wrapper instance; unused, but its absence is fatal. */
    public mixed $context = null;

    /** @var resource|null */
    private $handle;

    private static bool $registered = false;

    /** @var list<array{namespace: string, class: string}>|null */
    private static ?array $targets = [];

    /**
     * The allow-list as it stands, for a test driving the wrapper's methods
     * directly rather than through a registration.
     *
     * @param list<array{namespace: string, class: string}>|null $targets
     */
    public static function targetOnly(?array $targets): void
    {
        self::$targets = $targets;
    }

    /**
     * Installs the wrapper, or widens what it already covers.
     *
     * @param list<array{namespace: string, class: string}>|null $targets null asks for every class
     *                                                                    declaration, which is the
     *                                                                    global mode
     */
    public static function install(?array $targets): void
    {
        if ($targets === null) {
            self::$targets = null;
        } elseif (self::$targets !== null) {
            self::$targets = [...self::$targets, ...$targets];
        }

        if (self::$registered) {
            return;
        }

        stream_wrapper_unregister(self::PROTOCOL);
        stream_wrapper_register(self::PROTOCOL, self::class);
        self::$registered = true;
    }

    public static function isInstalled(): bool
    {
        return self::$registered;
    }

    /**
     * Restores the native wrapper. Nothing already compiled changes back — a
     * class is read once per process — so this is for a test suite tearing its
     * own process down, not for use between tests.
     */
    public static function uninstall(): void
    {
        if (!self::$registered) {
            return;
        }

        stream_wrapper_restore(self::PROTOCOL);
        self::$registered = false;
        self::$targets = [];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return $this->withoutWrapper(function () use ($path, $mode, $options, &$openedPath): bool {
            $usePath = (bool) ($options & STREAM_USE_PATH);

            if (!$this->isReadOfPhpSource($path, $mode)) {
                $handle = fopen($path, $mode, $usePath, $this->contextOrNull());

                if ($handle === false) {
                    return false;
                }

                $this->handle = $handle;

                return true;
            }

            $source = file_get_contents($path);

            if ($source === false) {
                return false;
            }

            $memory = fopen('php://memory', 'r+b');
            \assert($memory !== false);
            fwrite($memory, FinalStripper::strip($source, self::$targets));
            rewind($memory);
            $this->handle = $memory;

            // PHP asks for the resolved path when STREAM_USE_PATH is set, and
            // it has to be the real one: this is what `__FILE__` becomes.
            if ($usePath) {
                $openedPath = $path;
            }

            return true;
        });
    }

    public function stream_read(int $count): string|false
    {
        \assert($this->handle !== null);

        return fread($this->handle, $count);
    }

    public function stream_write(string $data): int
    {
        \assert($this->handle !== null);

        return (int) fwrite($this->handle, $data);
    }

    public function stream_tell(): int
    {
        \assert($this->handle !== null);

        return (int) ftell($this->handle);
    }

    public function stream_eof(): bool
    {
        \assert($this->handle !== null);

        return feof($this->handle);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        \assert($this->handle !== null);

        return fseek($this->handle, $offset, $whence) === 0;
    }

    public function stream_flush(): bool
    {
        \assert($this->handle !== null);

        return fflush($this->handle);
    }

    /**
     * @return array<mixed>|false
     */
    public function stream_stat(): array|false
    {
        \assert($this->handle !== null);

        return fstat($this->handle);
    }

    public function stream_close(): void
    {
        if ($this->handle === null) {
            return;
        }

        $handle = $this->handle;
        // Cleared first: `fclose()` leaves a closed resource behind, and the
        // property's type says it holds an open one or nothing.
        $this->handle = null;
        fclose($handle);
    }

    public function stream_set_option(): bool
    {
        // Nothing here changes blocking or timeouts; answering false would make
        // callers think the stream refused something it was never asked.
        return false;
    }

    /**
     * @return array<mixed>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        // Always quiet, and `STREAM_URL_STAT_QUIET` is therefore not consulted:
        // a `false` is the answer, and a warning raised in here is one the
        // caller cannot suppress. The same reason `stream_open()` is silent —
        // and one fewer branch whose mutant would talk.
        return $this->withoutWrapper(static fn(): array|false => ($flags & STREAM_URL_STAT_LINK) !== 0
            ? @lstat($path)
            : @stat($path));
    }

    public function unlink(string $path): bool
    {
        return $this->withoutWrapper(static fn(): bool => unlink($path));
    }

    public function rename(string $from, string $to): bool
    {
        return $this->withoutWrapper(static fn(): bool => rename($from, $to));
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return $this->withoutWrapper(static fn(): bool => mkdir($path, $mode, ($options & STREAM_MKDIR_RECURSIVE) !== 0));
    }

    public function rmdir(string $path): bool
    {
        return $this->withoutWrapper(static fn(): bool => rmdir($path));
    }

    public function dir_opendir(string $path): bool
    {
        // Quiet for the same reason as `stream_open()`.
        $handle = $this->withoutWrapper(static fn() => @opendir($path));

        if ($handle === false) {
            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function dir_readdir(): string|false
    {
        \assert($this->handle !== null);

        return readdir($this->handle);
    }

    public function dir_rewinddir(): bool
    {
        \assert($this->handle !== null);
        rewinddir($this->handle);

        return true;
    }

    public function dir_closedir(): bool
    {
        if ($this->handle === null) {
            return true;
        }

        $handle = $this->handle;
        $this->handle = null;
        closedir($handle);

        return true;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return $this->withoutWrapper(static fn(): bool => match ($option) {
            STREAM_META_TOUCH => self::touch($path, $value),
            STREAM_META_OWNER, STREAM_META_OWNER_NAME => (\is_int($value) || \is_string($value)) && chown($path, $value),
            STREAM_META_GROUP, STREAM_META_GROUP_NAME => (\is_int($value) || \is_string($value)) && chgrp($path, $value),
            STREAM_META_ACCESS => \is_int($value) && chmod($path, $value),
            default => false,
        });
    }

    /**
     * `STREAM_META_TOUCH` carries `[mtime, atime]`, both optional and both
     * integers; anything else is not a touch this wrapper can forward.
     */
    private static function touch(string $path, mixed $value): bool
    {
        if (!\is_array($value)) {
            return touch($path);
        }

        $mtime = $value[0] ?? null;
        $atime = $value[1] ?? null;

        if ($mtime !== null && !\is_int($mtime) || $atime !== null && !\is_int($atime)) {
            return false;
        }

        return touch($path, $mtime, $atime);
    }

    /**
     * Only a plain read of something that looks like PHP source is transformed.
     * A write, an append, or a `.json` being read is none of this wrapper's
     * business.
     */
    private function isReadOfPhpSource(string $path, string $mode): bool
    {
        return !str_contains($mode, '+')
            && str_contains($mode, 'r')
            && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php'
            && is_file($path);
    }

    /**
     * Runs one filesystem operation with the native wrapper in place.
     *
     * Without this every delegated call would re-enter this class and recurse
     * until the stack ran out.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function withoutWrapper(callable $operation): mixed
    {
        if (!self::$registered) {
            // Nobody installed this instance — a test driving the methods
            // directly, or PHP holding one after `uninstall()`. Restoring and
            // re-registering here would install the wrapper as a side effect of
            // reading one file, and every read after it would be transformed by
            // a wrapper the process never asked for.
            return $operation();
        }

        stream_wrapper_restore(self::PROTOCOL);

        try {
            return $operation();
        } finally {
            stream_wrapper_unregister(self::PROTOCOL);
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
    }

    /** @return resource|null */
    private function contextOrNull()
    {
        return \is_resource($this->context) ? $this->context : null;
    }
}
