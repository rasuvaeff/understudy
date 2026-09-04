<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Bypass;

/**
 * Removes the `final` token from a class declaration, and from nothing else.
 *
 * Token-aware rather than textual: `final` is a word that appears in comments,
 * strings and method declarations, and a regular expression that caught those
 * would change code nobody asked it to change. The tokenizer is also what makes
 * the case-insensitivity of PHP keywords somebody else's problem — `Final` and
 * `FINAL` are the same token.
 *
 * What is deliberately left alone: `final` on methods and constants. A final
 * method still cannot be overridden after the class opens up, and a double that
 * silently let one through would run the target's real code. `DoubleFactory`
 * refuses such a class either way.
 *
 * @internal
 */
final class FinalStripper
{
    private function __construct() {}

    /**
     * @param list<array{namespace: string, class: string}>|null $targets null strips every class
     *                                                                    declaration in the file
     */
    public static function strip(string $source, ?array $targets): string
    {
        // A file without the word cannot have the token, and reading every file
        // in a project through the tokenizer would be the expensive part.
        if (stripos($source, 'final') === false) {
            return $source;
        }

        // `finally` contains the word, and so does every comment that uses it:
        // 58% of a real vendor tree passes the guard above, and in targeted
        // mode almost none of it declares anything asked for. A file that does
        // not mention a target's class name cannot declare that class, so the
        // tokenizer never has to see it — measured at 0.55 ms against 0.016 ms
        // for the check, or about a second of tokenising per process.
        //
        // Necessary rather than sufficient, which is the only reason it is
        // safe: `class Foo` puts `Foo` in the source, so the absence of the
        // name is proof, while its presence proves nothing and the tokenizer
        // still decides. Case-insensitive, because PHP class names are.
        if ($targets !== null && !self::mentionsATarget($source, $targets)) {
            return $source;
        }

        $tokens = token_get_all($source);
        $namespace = '';
        $output = '';
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (\is_array($token) && $token[0] === T_NAMESPACE) {
                $namespace = self::namespaceAt($tokens, $index);
            }

            if (\is_array($token)
                && $token[0] === T_FINAL
                && self::opensATargetClass($tokens, $index, $namespace, $targets)) {
                // The single space after the keyword goes with it, so the file
                // stays byte-identical apart from the word. Whitespace holding a
                // newline is kept: dropping it would move every line below, and
                // a stack trace or a coverage report pointing one line off is
                // worse than a double space.
                $next = $tokens[$index + 1] ?? null;

                if (\is_array($next) && $next[0] === T_WHITESPACE && !str_contains($next[1], "\n")) {
                    $index++;
                }

                continue;
            }

            $output .= \is_array($token) ? $token[1] : $token;
        }

        return $output;
    }

    /**
     * Whether the source names any target class at all.
     *
     * @param list<array{namespace: string, class: string}> $targets
     */
    private static function mentionsATarget(string $source, array $targets): bool
    {
        foreach ($targets as $target) {
            if (stripos($source, $target['class']) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function namespaceAt(array $tokens, int $index): string
    {
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (\is_array($token) && \in_array($token[0], [T_STRING, T_NAME_QUALIFIED], strict: true)) {
                return $token[1];
            }

            if ($token === ';' || $token === '{') {
                break;
            }
        }

        return '';
    }

    /**
     * True when this `final` opens a class declaration the caller asked about.
     *
     * The tokens between `final` and the name may include `readonly`, comments
     * and whitespace; anything else — `function`, `const` — means this `final`
     * belongs to a member, not to the class.
     *
     * @param list<array{0: int, 1: string, 2: int}|string>      $tokens
     * @param list<array{namespace: string, class: string}>|null $targets
     */
    private static function opensATargetClass(array $tokens, int $index, string $namespace, ?array $targets): bool
    {
        $count = count($tokens);
        $sawClass = false;

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (\is_array($token)
                && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_READONLY], strict: true)) {
                continue;
            }

            if (!$sawClass) {
                if (\is_array($token) && $token[0] === T_CLASS) {
                    $sawClass = true;

                    continue;
                }

                return false;
            }

            if (!\is_array($token) || $token[0] !== T_STRING) {
                return false;
            }

            if ($targets === null) {
                return true;
            }

            foreach ($targets as $target) {
                if ($target['class'] === $token[1] && $target['namespace'] === $namespace) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
