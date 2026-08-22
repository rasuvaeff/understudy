<?php

/**
 * Spike 06 — token-aware `file://` stream wrapper stripping class-level final.
 *
 * Promises under test (plan §5.3.1):
 * - a stream wrapper installed BEFORE the target is first loaded removes the
 *   `final` token from the allow-listed class declaration only;
 * - sibling classes in the same file keep their `final`;
 * - final METHODS are never touched;
 * - `__FILE__`, `__DIR__` and relative includes inside the transformed file
 *   keep pointing at the original path (no temp-copy);
 * - after the strip the class is extendable (eval'd subclass);
 * - an already-loaded class cannot be bypassed — detectable via class_exists.
 */

declare(strict_types=1);

namespace Understudy\Spikes\BypassFinals;

use function Understudy\Spikes\assertSame;
use function Understudy\Spikes\assertTrue;

require __DIR__ . '/../lib.php';

final class FinalStripWrapper
{
    /** @var resource|null */
    public $context;

    /** @var resource|null */
    private $handle;

    /** @var list<array{namespace: string, class: string}> */
    private static array $targets = [];

    private static bool $registered = false;

    public static function allow(string $fqcn): void
    {
        $pos = strrpos($fqcn, '\\');
        self::$targets[] = [
            'namespace' => $pos === false ? '' : substr($fqcn, 0, $pos),
            'class' => $pos === false ? $fqcn : substr($fqcn, $pos + 1),
        ];

        if (!self::$registered) {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
            self::$registered = true;
        }
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        stream_wrapper_restore('file');

        try {
            if (!str_contains($mode, 'r') || str_contains($mode, '+') || !is_file($path)) {
                $this->handle = @fopen($path, $mode, (bool) ($options & STREAM_USE_PATH), $this->context);
            } else {
                $source = file_get_contents($path);
                $transformed = self::transform($source);
                $this->handle = fopen('php://memory', 'r+b');
                fwrite($this->handle, $transformed);
                rewind($this->handle);
            }
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
        }

        return $this->handle !== false;
    }

    private static function transform(string $source): string
    {
        // `final` is a case-insensitive keyword: the pre-filter must match
        // the tokenizer, which already handles `Final`/`FINAL`.
        if (stripos($source, 'final') === false) {
            return $source;
        }

        $tokens = token_get_all($source);
        $namespace = '';
        $output = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_NAMESPACE) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    $next = $tokens[$j];
                    if (is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace = $next[1];
                        break;
                    }
                    if ($next === ';' || $next === '{') {
                        break;
                    }
                }
            }

            if (is_array($token) && $token[0] === T_FINAL && self::finalBelongsToTargetClass($tokens, $i, $namespace)) {
                continue; // drop the `final` token (following whitespace collapses harmlessly)
            }

            $output .= is_array($token) ? $token[1] : $token;
        }

        return $output;
    }

    /**
     * `final` is dropped only when the next significant tokens form
     * `[readonly] class <AllowListedName>` in the allow-listed namespace.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function finalBelongsToTargetClass(array $tokens, int $index, string $namespace): bool
    {
        $count = count($tokens);
        $sawClass = false;

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_READONLY], true)) {
                continue;
            }

            if (!$sawClass) {
                if (is_array($token) && $token[0] === T_CLASS) {
                    $sawClass = true;
                    continue;
                }

                return false; // `final function`, `final const`, …
            }

            if (is_array($token) && $token[0] === T_STRING) {
                foreach (self::$targets as $target) {
                    if ($target['class'] === $token[1] && $target['namespace'] === $namespace) {
                        return true;
                    }
                }
            }

            return false;
        }

        return false;
    }

    public function stream_read(int $length): string|false
    {
        return fread($this->handle, $length);
    }

    public function stream_eof(): bool
    {
        return feof($this->handle);
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }

    public function stream_stat(): array|false
    {
        return fstat($this->handle);
    }

    /**
     * @return array<mixed>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        stream_wrapper_restore('file');

        try {
            return ($flags & STREAM_URL_STAT_QUIET) ? @stat($path) : @stat($path);
        } finally {
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', self::class);
        }
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return false;
    }
}

echo "06-bypass-finals\n";

assertTrue(
    !class_exists('Understudy\Spikes\BypassFinals\Fixture\FinalService', false),
    'target class is not loaded yet — bypass is possible',
);

FinalStripWrapper::allow('Understudy\Spikes\BypassFinals\Fixture\FinalService');

require __DIR__ . '/fixture/services.php';

$service = new \ReflectionClass(\Understudy\Spikes\BypassFinals\Fixture\FinalService::class);
assertTrue(!$service->isFinal(), 'allow-listed class lost its `final`');

$sibling = new \ReflectionClass(\Understudy\Spikes\BypassFinals\Fixture\FinalSibling::class);
assertTrue($sibling->isFinal(), 'sibling class in the same file keeps its `final`');

assertTrue(
    $service->getMethod('seal')->isFinal(),
    'final METHOD on the stripped class is untouched',
);

assertSame(
    \Understudy\Spikes\BypassFinals\Fixture\FinalService::reportedFile(),
    realpath(__DIR__ . '/fixture/services.php'),
    '__FILE__ inside the transformed file is the original path (no temp-copy)',
);

assertSame(
    \Understudy\Spikes\BypassFinals\Fixture\FinalService::reportedDir(),
    realpath(__DIR__ . '/fixture'),
    '__DIR__ is the original directory',
);

assertSame(
    \Understudy\Spikes\BypassFinals\Fixture\FinalService::relativeIncludeValue(),
    'relative-include-ok',
    'a relative include from inside the transformed file resolves',
);

eval('namespace Understudy\Spikes\BypassFinals; final class ServiceDouble extends \Understudy\Spikes\BypassFinals\Fixture\FinalService {}');
assertTrue(
    is_subclass_of(ServiceDouble::class, \Understudy\Spikes\BypassFinals\Fixture\FinalService::class),
    'the stripped class is now extendable by the codegen',
);

assertTrue(
    class_exists(\Understudy\Spikes\BypassFinals\Fixture\FinalSibling::class, false),
    'already-loaded sibling stays loaded — a later bypass attempt on it is detectable via class_exists',
);

// Case-insensitive keyword: `FINAL class` and `Final class` must strip too,
// while a non-allow-listed neighbour in the same file keeps its modifier.
FinalStripWrapper::allow('Understudy\Spikes\BypassFinals\Fixture\ShoutingFinalService');

require __DIR__ . '/fixture/shouting.php';

assertTrue(
    !(new \ReflectionClass(\Understudy\Spikes\BypassFinals\Fixture\ShoutingFinalService::class))->isFinal(),
    '`FINAL class` (upper case keyword) is stripped as well',
);

assertTrue(
    (new \ReflectionClass(\Understudy\Spikes\BypassFinals\Fixture\TitleCaseFinalService::class))->isFinal(),
    '`Final class` neighbour outside the allow-list keeps its modifier',
);
