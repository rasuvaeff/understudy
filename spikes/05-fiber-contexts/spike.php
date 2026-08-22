<?php

/**
 * Spike 05 — Fiber-isolated runtime contexts with a weak owner index.
 *
 * Promises under test (plan §4.2, §5.2):
 * - the main flow and every Fiber get their own RuntimeContext via
 *   `WeakMap<Fiber, Context>`; sibling fibers never share the recording
 *   flag or the call log;
 * - a suspend/resume cycle keeps a fiber's context intact, including a
 *   recording phase that spans the suspension;
 * - a weak owner index (`WeakMap<double, Context>`) routes a normal call
 *   made INSIDE a child fiber to the owner's log instead of the fiber's.
 */

declare(strict_types=1);

namespace Understudy\Spikes\FiberContexts;

use function Understudy\Spikes\assertSame;
use function Understudy\Spikes\assertTrue;

require __DIR__ . '/../lib.php';

final class Context
{
    public bool $recording = false;

    /** @var list<array{string, array<mixed>}> */
    public array $log = [];
}

final class Signal extends \Exception
{
    public function __construct(public readonly string $method)
    {
        parent::__construct('sentinel');
    }
}

final class Runtime
{
    private static ?Context $main = null;

    /** @var \WeakMap<\Fiber, Context>|null */
    private static ?\WeakMap $fibers = null;

    /** @var \WeakMap<object, Context>|null */
    private static ?\WeakMap $owners = null;

    public static function current(): Context
    {
        $fiber = \Fiber::getCurrent();

        if ($fiber === null) {
            return self::$main ??= new Context();
        }

        self::$fibers ??= new \WeakMap();

        return self::$fibers[$fiber] ??= new Context();
    }

    public static function adopt(object $double): void
    {
        self::$owners ??= new \WeakMap();
        self::$owners[$double] = self::current();
    }

    public static function ownerOf(object $double): Context
    {
        return (self::$owners ?? new \WeakMap())[$double] ?? self::current();
    }

    /**
     * @return array{fibers: int, owners: int}
     */
    public static function indexSizes(): array
    {
        return [
            'fibers' => count(self::$fibers ?? []),
            'owners' => count(self::$owners ?? []),
        ];
    }

    public static function dispatch(object $double, string $method, array $args): mixed
    {
        $owner = self::ownerOf($double);

        if ($owner->recording) {
            throw new Signal($method);
        }

        $owner->log[] = [$method, $args];

        return null;
    }
}

interface Port
{
    public function send(string $payload): mixed;
}

final class PortDouble implements Port
{
    public function send(string $payload): mixed
    {
        return Runtime::dispatch($this, __FUNCTION__, [$payload]);
    }
}

function makeDouble(): PortDouble
{
    $double = new PortDouble();
    Runtime::adopt($double);

    return $double;
}

echo "05-fiber-contexts\n";

$mainDouble = makeDouble();
$mainContext = Runtime::current();

// Fiber A starts a recording phase, then suspends in the middle of it.
$fiberA = new \Fiber(static function () use (&$stateA): void {
    $context = Runtime::current();
    $context->recording = true;

    \Fiber::suspend('recording-started');

    $own = makeDouble();

    try {
        $own->send('captured');
        $stateA = 'no-signal';
    } catch (Signal $signal) {
        $stateA = 'signal:' . $signal->method;
    }

    $context->recording = false;
});

// Fiber B runs normal-phase work while A is suspended mid-recording.
$fiberB = new \Fiber(static function (PortDouble $foreign) use (&$stateB): array {
    $context = Runtime::current();
    $stateB = $context->recording;

    $own = makeDouble();
    $own->send('local');

    // A double owned by the MAIN context, invoked inside this fiber:
    $foreign->send('routed');

    return $context->log;
});

assertSame($fiberA->start(), 'recording-started', 'fiber A suspended inside its recording phase');

$fiberB->start($mainDouble);
$logB = $fiberB->getReturn();

assertSame($stateB, false, "fiber B's context does not inherit fiber A's recording flag");
assertSame($logB, [['send', ['local']]], "fiber B's log holds only its own double's call");
assertSame(
    $mainContext->log,
    [['send', ['routed']]],
    'a call made inside fiber B on a main-owned double lands in the OWNER log',
);

$fiberA->resume();
assertSame($stateA, 'signal:send', "fiber A's recording phase survived suspend/resume and captured the sentinel");

assertSame(Runtime::current(), $mainContext, 'main context is stable across fiber lifecycles');
assertTrue($mainContext->recording === false, 'main context never saw a recording flag');

$mainDouble->send('after');
assertSame(count($mainContext->log), 2, 'main-owned double keeps logging into the main context');

// Both indexes must be genuinely WEAK: with strong references every assertion
// above would still pass while leaking one Context per fiber and one entry
// per double for the lifetime of the process.
$before = Runtime::indexSizes();

$temporary = new \Fiber(static function (): void {
    $double = makeDouble();
    $double->send('temporary');

    // Suspend while still holding the double, so both are observably alive.
    \Fiber::suspend();
});
$temporary->start();

$during = Runtime::indexSizes();
assertSame($during['fibers'], $before['fibers'] + 1, 'a live fiber holds exactly one context');
assertSame($during['owners'], $before['owners'] + 1, 'its double holds exactly one owner entry');

$temporary->resume();
unset($temporary);
gc_collect_cycles();

assertSame(
    Runtime::indexSizes(),
    $before,
    'both indexes release the fiber context and the owner entry once collected',
);
