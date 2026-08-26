<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Expectation;

/**
 * Renders one argument for a failure message: short enough to scan, precise
 * enough to tell `'1'` from `1`, and to tell one `Book` from another.
 *
 * @internal
 */
final class ArgumentFormatter
{
    private const int MAX_STRING = 40;

    /** Deep structures are for a debugger, not for a failure message. */
    private const int MAX_DEPTH = 3;

    /** As many public properties as an array shows entries. */
    private const int MAX_PROPERTIES = 5;

    /** A property name that needs no quoting to be read back. */
    private const string IDENTIFIER = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/';

    /**
     * Objects rendered so far in this message, in order of first appearance.
     *
     * Aliases are per message, not per process: `spl_object_id()` depends on
     * allocation order and is reused after a collection, so the same failing
     * test would print different numbers on different runs.
     *
     * @var \SplObjectStorage<object, int>|null
     */
    private static ?\SplObjectStorage $aliases = null;

    private static int $rendered = 0;

    private function __construct() {}

    /**
     * Renders everything the callback renders against one alias table, so an
     * instance that appears both in the expectation and in the call log keeps
     * one name across the whole message.
     *
     * Nesting is a no-op: the outermost scope owns the table. The scope is one
     * `VerificationFailure`, not the whole report — a failure's `summary` is
     * read on its own, and numbering that depended on sibling failures would
     * change when an unrelated one appeared.
     *
     * @template T
     *
     * @param callable(): T $render
     *
     * @return T
     */
    public static function scope(callable $render): mixed
    {
        if (self::$aliases !== null) {
            return $render();
        }

        self::$aliases = new \SplObjectStorage();
        self::$rendered = 0;

        try {
            return $render();
        } finally {
            self::$aliases = null;
        }
    }

    /**
     * @return non-empty-string
     */
    public static function format(mixed $value, int $depth = 0): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '…';
        }

        if (self::$aliases === null) {
            return self::scope(static fn(): string => self::format($value, $depth));
        }

        $rendered = match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => self::formatString($value),
            is_array($value) => self::formatArray($value, $depth),
            $value instanceof \UnitEnum => $value::class . '::' . $value->name,
            is_object($value) => self::formatObject($value, $depth),
            default => get_debug_type($value),
        };
        \assert($rendered !== '');

        return $rendered;
    }

    /**
     * @return non-empty-string
     */
    private static function formatString(string $value): string
    {
        $truncated = self::truncate($value);

        // A raw newline or quote would break the single line a failure message
        // renders each argument on, and hide what actually differed.
        //
        // Quotes and backslashes are escaped first: doing it the other way
        // round would escape the backslashes this step introduces, turning a
        // newline into a literal `\\n`.
        $escaped = strtr(addcslashes($truncated, "'\\"), [
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]);

        return "'" . $escaped . "'";
    }

    /**
     * Cuts to MAX_STRING characters, not bytes — splitting a multibyte
     * character would render mojibake in the middle of a failure message.
     * PCRE does the counting, so the package needs no ext-mbstring.
     */
    private static function truncate(string $value): string
    {
        if (preg_match_all('/./us', $value, $characters) === false) {
            // Not valid UTF-8: there are no characters to count, only bytes.
            return strlen($value) > self::MAX_STRING
                ? substr($value, 0, self::MAX_STRING) . '…'
                : $value;
        }

        return count($characters[0]) > self::MAX_STRING
            ? implode('', array_slice($characters[0], 0, self::MAX_STRING)) . '…'
            : $value;
    }

    /**
     * Two instances of the same class never match by `===`, and the report has
     * to say which of the two reasons it was: a rebuilt copy carrying equal
     * data, or an object shaped differently. The alias answers the first
     * question, the public properties the second.
     *
     * `get_object_vars()` from outside the class reads public state only, and
     * never runs a getter or `__get()` — rendering a message must not execute
     * the code under test. An object with nothing public to compare renders as
     * its alias alone.
     *
     * @return non-empty-string
     */
    private static function formatObject(object $value, int $depth): string
    {
        $alias = $value::class . '#' . self::alias($value);
        $properties = get_object_vars($value);

        if ($properties === []) {
            return $alias;
        }

        $parts = [];

        /** @var mixed $property */
        foreach (array_slice($properties, 0, self::MAX_PROPERTIES, preserve_keys: true) as $name => $property) {
            $parts[] = self::formatPropertyName($name) . ': ' . self::format($property, $depth + 1);
        }

        if (count($properties) > self::MAX_PROPERTIES) {
            $parts[] = '…';
        }

        return $alias . ' {' . implode(', ', $parts) . '}';
    }

    private static function alias(object $value): int
    {
        $table = self::$aliases;
        \assert($table !== null);

        if (!$table->contains($value)) {
            $table[$value] = ++self::$rendered;
        }

        return $table[$value];
    }

    /**
     * A property name is normally an identifier and reads best bare. A dynamic
     * one — `json_decode()` fills a `stdClass` from whatever the payload said —
     * is not, and gets the same quoting a string argument gets.
     *
     * @return non-empty-string
     */
    private static function formatPropertyName(string|int $name): string
    {
        $rendered = match (true) {
            is_int($name) => (string) $name,
            preg_match(self::IDENTIFIER, $name) === 1 => self::truncate($name),
            default => self::formatString($name),
        };
        \assert($rendered !== '');

        return $rendered;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return non-empty-string
     */
    private static function formatArray(array $value, int $depth): string
    {
        if ($value === []) {
            return '[]';
        }

        $isList = array_is_list($value);
        $parts = [];

        /** @var mixed $item */
        foreach (array_slice($value, 0, 5, preserve_keys: true) as $key => $item) {
            $parts[] = $isList
                ? self::format($item, $depth + 1)
                : self::format($key, $depth + 1) . ' => ' . self::format($item, $depth + 1);
        }

        if (count($value) > 5) {
            $parts[] = '…';
        }

        return '[' . implode(', ', $parts) . ']';
    }
}
