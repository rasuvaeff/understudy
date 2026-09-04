<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Tests\Fixture\Bypass;

/**
 * The declaration writes the class name in a case nobody references it by.
 * PHP does not care — `class_exists()` resolves it under either spelling — and
 * neither should the strip: comparing the name with `===` walked past this
 * very declaration and left it final, with nothing said until `for()` refused
 * it.
 */
final class lowercasegate {}
