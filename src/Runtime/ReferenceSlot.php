<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Runtime;

/**
 * The storage a by-reference return points into.
 *
 * A method declared `&method()` promises the caller a reference to something
 * that outlives the call, so it cannot point at a local — the next call would
 * replace it and nothing written through it would survive. It points here
 * instead: one holder per double per method, owned by the double's state.
 *
 * A plain object with a public property, rather than a reference handed back
 * through the runtime, because the only code that takes the reference is the
 * generated method — which keeps every `&` out of the analysed source.
 *
 * @internal
 */
final class ReferenceSlot
{
    public mixed $value = null;
}
