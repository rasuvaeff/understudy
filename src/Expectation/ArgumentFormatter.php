<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Expectation;

/**
 * Renders one argument for a failure message: short enough to scan, precise
 * enough to tell `'1'` from `1`.
 *
 * @internal
 */
final class ArgumentFormatter
{
    private const int MAX_STRING = 40;

    /** Deep structures are for a debugger, not for a failure message. */
    private const int MAX_DEPTH = 3;

    private function __construct() {}

    /**
     * @return non-empty-string
     */
    public static function format(mixed $value, int $depth = 0): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '…';
        }

        $rendered = match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => self::formatString($value),
            is_array($value) => self::formatArray($value, $depth),
            $value instanceof \UnitEnum => $value::class . '::' . $value->name,
            is_object($value) => $value::class,
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
        $truncated = mb_strlen($value) > self::MAX_STRING
            ? mb_substr($value, 0, self::MAX_STRING) . '…'
            : $value;

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
