<?php

declare(strict_types=1);

/**
 * Loaded by `opcache.preload`, before any bootstrap of ours could run.
 *
 * That is the whole point: a preloaded class is in memory before bypass could
 * have been asked for, so there is nothing left to lift and the refusal says
 * so. Started by `BypassFinalsIntegrationTest`, never on its own.
 */

require __DIR__ . '/SealedGate.php';
