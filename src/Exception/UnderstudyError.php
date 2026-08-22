<?php

declare(strict_types=1);

namespace Rasuvaeff\Understudy\Exception;

/**
 * Implemented by every exception this library throws, so a test can catch
 * misuse of Understudy itself without catching the errors it reports about
 * the code under test.
 *
 * @api
 */
interface UnderstudyError extends \Throwable {}
