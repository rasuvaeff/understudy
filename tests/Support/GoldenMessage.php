<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Support;

/**
 * Reads a golden file for a multi-line failure message, from
 * `tests/fixtures/messages/<name>.txt`.
 *
 * A rendered report — a header, a call log, an argument marked with `*` — is
 * a document, not a string literal: a wording review reads the `.txt` diff,
 * not PHP concatenation. Single-line messages stay inline; a message grows a
 * golden file when it starts rendering calls.
 *
 * Line endings are normalized to LF and a single trailing newline is
 * trimmed on read: git may check the file out with CRLF on Windows, and the
 * comparison must hold on every platform.
 */
final class GoldenMessage
{
    public static function read(string $name): string
    {
        $path = __DIR__ . '/../fixtures/messages/' . $name . '.txt';

        if (!is_file($path)) {
            throw new \RuntimeException('Missing golden message file: ' . $path);
        }

        /** @var string $content */
        $content = file_get_contents($path);

        return rtrim(str_replace("\r\n", "\n", $content), "\n");
    }
}
