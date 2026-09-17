<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Expectation;

use Rasuvaeff\Understudy\Codegen\MethodSignature;

/**
 * What a specified method may answer with, as `returns()` needs it: enough of
 * the signature to refuse a value the declared type cannot hold, and the
 * names a refusal has to carry.
 *
 * Checked at registration rather than at the call, because that is where the
 * mistake was made. Left to the engine, the same value surfaced as a
 * `TypeError` naming the generated class — `Understudy_a44bd4375e66f225::find()`
 * — from wherever inside the code under test the call happened to be.
 *
 * @internal
 */
final readonly class ReturnContract
{
    /**
     * @param non-empty-string $label        the double, as a refusal names it
     * @param non-empty-string $method
     * @param non-empty-string $selfContract what `self` and `static` resolve to
     */
    public function __construct(
        public string $label,
        public string $method,
        public MethodSignature $signature,
        public string $selfContract,
    ) {}

    /**
     * Whether the declared return type can hold the value.
     *
     * Deliberately no stricter than the engine: a generated method is not
     * under `strict_types`, so a scalar declared type takes any scalar and a
     * `string` takes a `Stringable` — `returns('5')` on `: int` answers `5`
     * today and keeps doing so. What is refused is what PHP would refuse too:
     * `null` where the type is not nullable, an array or an object where a
     * scalar is declared, an object of the wrong class. A type this cannot
     * read is accepted, so a wrong verdict here is impossible by construction.
     */
    public function accepts(mixed $value): bool
    {
        return $this->acceptsType($value, $this->signature->returnType);
    }

    private function acceptsType(mixed $value, string $type): bool
    {
        if ($type === 'mixed') {
            return true;
        }

        if (str_starts_with($type, '?')) {
            return $value === null || $this->acceptsType($value, substr($type, 1));
        }

        if (str_contains($type, '|')) {
            foreach ($this->branches($type) as $branch) {
                if ($this->acceptsType($value, $branch)) {
                    return true;
                }
            }

            return false;
        }

        if (str_contains($type, '&')) {
            foreach (explode('&', $type) as $member) {
                if (!$this->acceptsType($value, $member)) {
                    return false;
                }
            }

            return true;
        }

        // A class name arrives absolute (`\App\Book`), which `class_exists()`
        // and `instanceof` both read as written.
        return match ($type) {
            // `returns(null)` on a void method is the idiom for "answer
            // nothing"; a value is refused before this table is consulted.
            'null', 'void' => $value === null,
            'never' => false,
            'int', 'float', 'bool', 'true', 'false' => is_scalar($value),
            'string' => is_scalar($value) || $value instanceof \Stringable,
            'array' => is_array($value),
            'iterable' => is_iterable($value),
            'callable' => is_callable($value),
            'object' => is_object($value),
            'self', 'static' => $value instanceof $this->selfContract,
            default => !class_exists($type) && !interface_exists($type) || $value instanceof $type,
        };
    }

    /**
     * The top-level members of a union, with a parenthesised intersection
     * kept whole.
     *
     * @return list<string>
     */
    private function branches(string $type): array
    {
        $branches = [];
        $depth = 0;
        $current = '';

        foreach (str_split($type) as $character) {
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            }

            if ($character === '|' && $depth === 0) {
                $branches[] = $current;
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $branches[] = $current;

        // A DNF member is parenthesised on the wire, `(A&B)|null`; the
        // intersection rule reads it without the parentheses.
        return array_map(static fn(string $branch): string => trim($branch, '()'), $branches);
    }
}
