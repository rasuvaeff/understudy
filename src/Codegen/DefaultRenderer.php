<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Codegen;

use Rasuvaeff\Understudy\Exception\UnsupportedTarget;

/**
 * A parameter default as text: the key `TargetUnifier` compares when two
 * targets declare the same position, and what a conflict names.
 *
 * @internal
 */
final class DefaultRenderer
{
    private function __construct() {}

    /**
     * A parameter's default as a comparison key between two targets that
     * declare the same position — and as the text a conflict names.
     *
     * Not source the generated class carries: since #123 every generated
     * parameter defaults to the arity sentinel, and dispatch reads the real
     * default from the declaring parameter, where `self::` still means the
     * declaring class. A value that `var_export()` renders is compared by
     * value, so `= 5` and `= 0x5` agree; anything else — an object, an
     * expression over `self::` — is compared by its source with the class
     * keywords qualified, which may call two equal defaults different (a
     * refusal) but never two different ones equal.
     *
     * The source expression comes from `ReflectionParameter::__toString()`,
     * which renders it fully qualified and without reading the declaring file,
     * so it works for `eval`'d and internal declarations too. It is also the
     * only way to *see* an object default without evaluating it:
     * `getDefaultValue()` on `= new Foo()` runs the constructor.
     *
     * @param non-empty-string $method
     *
     * @return non-empty-string
     */
    public static function render(\ReflectionParameter $parameter, string $method): string
    {
        $source = self::defaultSource($parameter);

        if ($source !== null && self::buildsAnObject($source)) {
            return self::renderSourceDefault($parameter, $source);
        }

        if ($parameter->isDefaultValueConstant()) {
            $rendered = self::renderConstantDefault($parameter);

            if ($rendered !== null) {
                return $rendered;
            }
        }

        return self::renderValueDefault($parameter, $method);
    }

    /**
     * The default expression as PHP would print it, or null when Reflection
     * reports none.
     */
    private static function defaultSource(\ReflectionParameter $parameter): ?string
    {
        $rendered = (string) $parameter;
        $position = strpos($rendered, ' = ');

        if ($position === false) {
            return null;
        }

        if (!str_ends_with($rendered, ' ]')) {
            return null;
        }

        return substr($rendered, $position + \strlen(' = '), -\strlen(' ]'));
    }

    /**
     * True when the default builds an object anywhere inside it — `new Foo()`,
     * but also `[new Foo()]` or `['k' => new Foo()]`, which are just as legal in
     * an initializer and just as destructive to evaluate: `getDefaultValue()`
     * runs every constructor in there.
     *
     * Quoted text is blanked out first, so a string default that merely contains
     * the word is not mistaken for an expression.
     */
    private static function buildsAnObject(string $source): bool
    {
        return preg_match('/\bnew\s+[\\\\A-Za-z_]/', self::withoutStringLiterals($source)) === 1;
    }

    /**
     * Blanks out single- and double-quoted runs, keeping the length so nothing
     * else shifts. An escaped quote stays inside its run.
     */
    private static function withoutStringLiterals(string $source): string
    {
        return (string) preg_replace_callback(
            '/\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"/s',
            static fn(array $match): string => str_repeat(' ', \strlen($match[0])),
            $source,
        );
    }

    /**
     * The default's own source, which Reflection has already qualified —
     * except for `self`, `static` and `parent`, which are qualified here by
     * the declaring class: two targets writing `self::LIMIT` name two
     * different constants.
     *
     * @return non-empty-string
     */
    private static function renderSourceDefault(\ReflectionParameter $parameter, string $source): string
    {
        $declaring = $parameter->getDeclaringClass();
        $blanked = self::withoutStringLiterals($source);
        $qualified = '';
        $offset = 0;

        preg_match_all('/\b(self|static|parent)(?=\s*::)/i', $blanked, $keywords, PREG_OFFSET_CAPTURE);

        foreach ($keywords[1] as [$keyword, $at]) {
            $class = match (true) {
                !$declaring instanceof \ReflectionClass => $keyword,
                strtolower($keyword) === 'parent' => ($parent = $declaring->getParentClass()) === false ? $keyword : '\\' . $parent->getName(),
                default => '\\' . $declaring->getName(),
            };
            $qualified .= substr($source, $offset, $at - $offset) . $class;
            $offset = $at + \strlen($keyword);
        }

        $qualified .= substr($source, $offset);
        \assert($qualified !== '');

        return $qualified;
    }

    /**
     * A class constant or enum case, rendered through its declaring class so
     * that `self::` does not follow the double into a class that never had the
     * constant. Returns null when the constant cannot be named from outside —
     * a private or protected one, or a global constant, which Reflection
     * reports prefixed with the declaring namespace and therefore under a name
     * that does not exist.
     *
     * @return non-empty-string|null
     */
    private static function renderConstantDefault(\ReflectionParameter $parameter): ?string
    {
        $name = $parameter->getDefaultValueConstantName();

        if ($name === null) {
            return null;
        }

        if (!str_contains($name, '::')) {
            return null;
        }

        $parts = explode('::', $name, 2);
        $class = $parts[0];
        $constant = $parts[1] ?? '';
        $declaring = $parameter->getDeclaringClass();

        if (\in_array(strtolower($class), ['self', 'static', 'parent'], strict: true)) {
            if (!$declaring instanceof \ReflectionClass) {
                return null;
            }

            if (strtolower($class) === 'parent') {
                $parent = $declaring->getParentClass();

                if ($parent === false) {
                    return null;
                }

                $class = $parent->getName();
            } else {
                $class = $declaring->getName();
            }
        }

        if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
            return null;
        }

        $reflection = new \ReflectionClass($class);

        if (!$reflection->hasConstant($constant)) {
            return null;
        }

        $declared = $reflection->getReflectionConstant($constant);

        if ($declared === false || !$declared->isPublic()) {
            // Reachable only from inside the declaring hierarchy, which the
            // generated class is not part of; fall back to the value.
            return null;
        }

        return '\\' . $class . '::' . $constant;
    }

    /**
     * The evaluated value, for everything that survives `var_export()`: scalars,
     * null, arrays of them, and enum cases. An object anywhere inside is
     * compared by its source instead; only an object default Reflection shows
     * no source for is refused, since nothing could tell two of them apart.
     *
     * @param non-empty-string $method
     *
     * @return non-empty-string
     */
    private static function renderValueDefault(\ReflectionParameter $parameter, string $method): string
    {
        /** @var mixed $value */
        $value = $parameter->getDefaultValue();

        if (self::holdsPlainObject($value)) {
            $source = self::defaultSource($parameter);

            if ($source !== null && $source !== '') {
                return self::renderSourceDefault($parameter, $source);
            }

            $format = 'the default of `$%s` in `%s()` holds an object that cannot be written back as source. '
                . 'Give the parameter a scalar, array or enum default, or double an interface.';

            throw UnsupportedTarget::notDoublable(
                $parameter->getDeclaringClass()?->getName() ?? $method,
                sprintf($format, $parameter->getName(), $method),
            );
        }

        // `var_export()` renders null as `NULL`, which is legal but jarring in
        // generated source next to hand-written PHP.
        $rendered = $value === null ? 'null' : var_export($value, return: true);
        \assert($rendered !== '');

        return $rendered;
    }

    /**
     * True when the value is, or contains, an object that is not an enum case.
     * Enum cases round-trip through `var_export()`; nothing else does.
     */
    private static function holdsPlainObject(mixed $value): bool
    {
        if ($value instanceof \UnitEnum) {
            return false;
        }

        if (\is_object($value)) {
            return true;
        }

        if (!\is_array($value)) {
            return false;
        }

        /** @var mixed $item */
        foreach ($value as $item) {
            if (self::holdsPlainObject($item)) {
                return true;
            }
        }

        return false;
    }
}
