<?php

declare(strict_types=1);

// Shared by every case study. The leading underscore marks it an include
// rather than a script of its own — the same convention examples/_check.php
// uses, and what docs/scripts/check-cookbook.mjs filters on.
//
// A case study prints a failure, so it must not exit non-zero because of it.
// `show()` runs a closure that is expected to throw, prints the message, and
// refuses to be silent if nothing throws: a case study whose failure stopped
// happening is a case study that no longer documents anything.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function show(callable $body): void
{
    try {
        $body();
    } catch (Throwable $e) {
        echo $e->getMessage(), "\n";

        return;
    }

    fwrite(STDERR, "Expected a failure, got none — this case study no longer reproduces.\n");
    exit(1);
}
