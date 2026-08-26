<?php

declare(strict_types=1);

/**
 * The one thing every example here shares: a check that fails loudly.
 *
 * `examples/` is part of the public contract, and `bin/package-audit` runs
 * every script listed in `README.md` as server-independent, failing on a
 * non-zero exit. That makes a script with checks in it a gate — it breaks when
 * the API it demonstrates changes. A script that only prints proves the file
 * parses.
 *
 * `assert()` is deliberately not used: it is compiled out under
 * `zend.assertions=-1`, and an example that silently stopped checking would
 * look exactly like one that passes.
 *
 * The leading underscore is what tells `bin/package-audit` this is an include,
 * not a script to run on its own.
 */

namespace Rasuvaeff\Understudy\Examples;

function check(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \RuntimeException('FAILED: ' . $what);
    }

    echo '  ok  ', $what, "\n";
}
