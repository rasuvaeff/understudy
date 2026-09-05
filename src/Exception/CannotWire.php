<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * `wire()` cannot build the subject, or cannot decide what to pass it.
 *
 * @api
 */
final class CannotWire extends \InvalidArgumentException implements UnderstudyError
{
    /**
     * Takes a plain string, not a `class-string`: "there is no such class" is
     * one of the reasons it reports.
     */
    public static function notAConcreteClass(string $sut, string $reason): self
    {
        return new self(sprintf(
            "Cannot wire `%s`: %s\n"
            . 'wire() builds a real subject out of doubled dependencies; the subject itself is not a double.',
            $sut,
            $reason,
        ));
    }

    /**
     * @param class-string $sut
     */
    public static function inaccessibleConstructor(string $sut, string $visibility): self
    {
        return new self(sprintf(
            "Cannot wire `%s`: its constructor is %s.\n"
            . 'Use the named constructor the class offers, or build the subject yourself and double its '
            . 'dependencies with Understudy::for().',
            $sut,
            $visibility,
        ));
    }

    /**
     * @param class-string $sut
     */
    public static function unknownOverride(string $sut, string $name, string $known): self
    {
        return new self(sprintf(
            "Cannot wire `%s`: there is no constructor parameter named `%s`.\n"
            . 'The constructor takes: %s.',
            $sut,
            $name,
            $known,
        ));
    }

    /**
     * @param class-string $sut
     */
    public static function incompatibleOverride(string $sut, string $name, string $expected, string $given): self
    {
        return new self(sprintf(
            "Cannot wire `%s`: the override for `\$%s` has type `%s`, and the constructor declares `%s`.\n"
            . 'The check happens before the constructor runs, so a wrong type is reported here rather than '
            . 'as a TypeError from inside the subject.',
            $sut,
            $name,
            $given,
            $expected,
        ));
    }

    /**
     * @param class-string $sut
     */
    public static function undecidableParameter(string $sut, string $name, string $type, string $reason): self
    {
        return new self(sprintf(
            "Cannot wire `%s`: nothing can be decided for `\$%s` (`%s`) — %s\n"
            . 'Pass it yourself: Understudy::wire(%s::class, [\'%s\' => $value]).',
            $sut,
            $name,
            $type,
            $reason,
            self::shortName($sut),
            $name,
        ));
    }

    /**
     * @param class-string $sut
     */
    public static function referenceParameter(string $sut, string $name): self
    {
        return new self(sprintf(
            "Cannot wire `%s`: `\$%s` is taken by reference.\n"
            . 'Overrides are values, and passing one would quietly promise a reference semantics wire() does '
            . 'not have. Build the subject yourself.',
            $sut,
            $name,
        ));
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
