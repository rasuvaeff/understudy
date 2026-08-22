<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Matcher;

/**
 * Renders the bounds of a range matcher the way they were written.
 *
 * @internal
 */
final class Bounds
{
    private function __construct() {}

    public static function describe(int|float|null $min, int|float|null $max): string
    {
        $low = $min === null ? null : 'min: ' . self::number($min);
        $high = $max === null ? null : 'max: ' . self::number($max);

        return implode(', ', array_filter([$low, $high], static fn(?string $part): bool => $part !== null));
    }

    private static function number(int|float $value): string
    {
        return (string) $value;
    }
}
