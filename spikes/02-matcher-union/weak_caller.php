<?php

// Intentionally NO declare(strict_types=1): this file represents SUT code
// compiled in weak mode. Coercion of scalar arguments must follow this file.

namespace Understudy\Spikes\MatcherUnion;

return static function (Calc $double): array {
    $double->scale('5');

    $last = Runtime::$log[array_key_last(Runtime::$log)];

    return $last[1];
};
