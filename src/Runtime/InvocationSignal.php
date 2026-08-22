<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

/**
 * Thrown by a generated method during a recording phase so that the call
 * aborts before it has to produce a value. Throwing is what lets a method
 * declared `: ?Book` — or even `: never` — hand its name and arguments back
 * to `when()` without violating its own return type.
 *
 * Never escapes the library: `when()`/`expect()`/`verify()` catch it.
 *
 * @internal
 */
final class InvocationSignal extends \Exception
{
    /**
     * @param non-empty-string $method
     * @param list<mixed>      $args
     */
    public function __construct(
        public readonly object $double,
        public readonly string $method,
        public readonly array $args,
    ) {
        parent::__construct('understudy recording signal');
    }
}
