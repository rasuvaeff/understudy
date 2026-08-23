<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Bypass;

/**
 * A second tool that also lifts `final` as source goes past — the shape of
 * `dg/bypass-finals`, and the one transform that genuinely cannot compose with
 * this package\'s: whichever runs second sees a file that no longer says what
 * the first one was told to look for.
 */
final class ForeignStripperWrapper
{
    public const string PROTOCOL = 'file';

    public mixed $context = null;

    private string $buffer = '';

    private int $offset = 0;

    public static function install(): void
    {
        stream_wrapper_unregister(self::PROTOCOL);
        stream_wrapper_register(self::PROTOCOL, self::class);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $source = self::withoutWrapper(static fn(): string|false => @file_get_contents($path));

        if (!\is_string($source)) {
            return false;
        }

        $this->buffer = str_ends_with(strtolower($path), '.php') ? self::transform($source) : $source;
        $this->offset = 0;
        $openedPath = $path;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->buffer, $this->offset, $count);
        $this->offset += \strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->offset >= \strlen($this->buffer);
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        $this->buffer = '';
        $this->offset = 0;
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        return self::withoutWrapper(
            static fn(): array|false => ($flags & STREAM_URL_STAT_QUIET) !== 0 ? @stat($path) : @stat($path),
        );
    }

    private static function transform(string $source): string
    {
        // Token-aware on purpose. A blunt `str_replace('final class ', ...)`
        // would also rewrite the string literal the refusal looks for inside
        // this package's own source, and the check would then pass by reading
        // its own marker back out of the rewrite — a fixture artefact that
        // looks exactly like a working bypass.
        $output = '';

        foreach (token_get_all($source) as $token) {
            if (\is_array($token) && $token[0] === T_FINAL) {
                continue;
            }

            $output .= \is_array($token) ? $token[1] : $token;
        }

        return $output;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     */
    private static function withoutWrapper(\Closure $read): mixed
    {
        stream_wrapper_restore(self::PROTOCOL);

        try {
            return $read();
        } finally {
            self::install();
        }
    }
}
