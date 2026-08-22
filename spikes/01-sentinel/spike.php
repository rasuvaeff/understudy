<?php

/**
 * Spike 01 — sentinel exception escapes any native return type.
 *
 * Promises under test (plan §4.1/§4.2, §5.1):
 * - a method declared `: ?Book` can abort via an exception while recording,
 *   bypassing the return type check entirely;
 * - the sentinel carries object id, method name and arguments;
 * - a `: never` method cannot return, but throwing the sentinel is legal,
 *   so recording works for `never` targets too;
 * - a `: void` method dispatches normally.
 */

declare(strict_types=1);

namespace Understudy\Spikes\Sentinel;

use function Understudy\Spikes\assertSame;
use function Understudy\Spikes\assertTrue;

require __DIR__ . '/../lib.php';

final class Book
{
}

interface BookRepository
{
    public function find(int $id): ?Book;

    public function abort(string $reason): never;

    public function touch(): void;
}

final class InvocationSignal extends \Exception
{
    /**
     * @param list<mixed> $args
     */
    public function __construct(
        public readonly int $objectId,
        public readonly string $method,
        public readonly array $args,
    ) {
        parent::__construct('understudy sentinel');
    }
}

final class Runtime
{
    /**
     * Recording is a DEPTH counter, not a boolean (plan §4.2): a nested
     * recording phase must not switch the outer one off when it unwinds.
     */
    public static int $recordingDepth = 0;

    public static function isRecording(): bool
    {
        return self::$recordingDepth > 0;
    }

    public static function dispatch(object $double, string $method, array $args): mixed
    {
        if (self::isRecording()) {
            throw new InvocationSignal(spl_object_id($double), $method, $args);
        }

        return null;
    }
}

final class BookRepositoryDouble implements BookRepository
{
    public function find(int $id): ?Book
    {
        return Runtime::dispatch($this, __FUNCTION__, [$id]);
    }

    public function abort(string $reason): never
    {
        Runtime::dispatch($this, __FUNCTION__, [$reason]);

        throw new \LogicException('never-method called on a double without expectation');
    }

    public function touch(): void
    {
        Runtime::dispatch($this, __FUNCTION__, []);
    }
}

/**
 * @return InvocationSignal
 */
function record(callable $closure): InvocationSignal
{
    Runtime::$recordingDepth++;

    try {
        $closure();
    } catch (InvocationSignal $signal) {
        return $signal;
    } finally {
        Runtime::$recordingDepth--;
    }

    throw new \LogicException('closure did not hit a double');
}

echo "01-sentinel\n";

$double = new BookRepositoryDouble();

$signal = record(fn () => $double->find(123));
assertSame($signal->method, 'find', 'sentinel escapes `: ?Book` and carries the method name');
assertSame($signal->args, [123], 'sentinel carries the arguments');
assertSame($signal->objectId, spl_object_id($double), 'sentinel identifies the double');

$signal = record(fn () => $double->abort('boom'));
assertSame($signal->method, 'abort', 'sentinel escapes `: never` during recording');

$double->touch();
assertTrue(true, '`: void` method dispatches normally outside recording');

assertSame($double->find(5), null, 'normal phase returns the dispatcher value under `: ?Book`');

// A nested recording phase must not switch the outer one off when it unwinds
// (this is why the flag is a depth counter, plan §4.2).
$outer = null;
$inner = null;
Runtime::$recordingDepth++;

try {
    $inner = record(fn () => $double->touch());

    try {
        $double->find(7);
    } catch (InvocationSignal $signal) {
        $outer = $signal;
    }
} finally {
    Runtime::$recordingDepth--;
}

assertSame($inner?->method, 'touch', 'nested recording phase captures its own signal');
assertSame($outer?->method, 'find', 'outer recording phase survives the nested one');
assertSame(Runtime::$recordingDepth, 0, 'recording depth unwinds back to zero');

