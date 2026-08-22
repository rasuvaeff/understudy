<?php

declare(strict_types=1);

// Strict-mode SUT file: the same '5' argument must raise a TypeError here.

namespace Understudy\Spikes\MatcherUnion;

return static function (Calc $double): string {
    return $double->scale('5');
};
