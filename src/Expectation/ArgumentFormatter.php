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

    private function __construct() {}

    /**
     * @return non-empty-string
     */
    public static function format(mixed $value): string
    {
        $rendered = match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => self::formatString($value),
            is_array($value) => self::formatArray($value),
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
        $escaped = mb_strlen($value) > self::MAX_STRING
            ? mb_substr($value, 0, self::MAX_STRING) . '…'
            : $value;

        return "'" . $escaped . "'";
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return non-empty-string
     */
    private static function formatArray(array $value): string
    {
        if ($value === []) {
            return '[]';
        }

        $isList = array_is_list($value);
        $parts = [];

        /** @var mixed $item */
        foreach (array_slice($value, 0, 5, preserve_keys: true) as $key => $item) {
            $parts[] = $isList
                ? self::format($item)
                : self::format($key) . ' => ' . self::format($item);
        }

        if (count($value) > 5) {
            $parts[] = '…';
        }

        return '[' . implode(', ', $parts) . ']';
    }
}
