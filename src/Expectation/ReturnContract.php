<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Expectation;

use Rasuvaeff\Understudy\Codegen\DnfType;
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
        return DnfType::parse($this->signature->returnType)->accepts($value, $this->selfContract);
    }
}
