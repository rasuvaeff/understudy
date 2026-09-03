<?php

declare(strict_types=1);

// Does the prose still describe the code?
//
// The generated reference cannot disagree with the engine — it is reflection.
// The cookbook cannot either — check-cookbook.mjs diffs its output. The
// hand-written guide is the part with nothing holding it: 30-odd pages
// transcribed from README.md, which is itself maintained by hand and can be
// wrong. It was: `expect(...)->times(minimum: 1)` appears in that README as
// "at least once" and means exactly once, and `expect(...)->never()` was
// documented and does not exist.
//
// So every load-bearing claim on those pages is asserted here, labelled with
// the page that makes it. A claim that stops holding fails the build instead
// of quietly becoming fiction.
//
// Run from the package root:
//   docker run --rm -v "$PWD":/app -w /app composer:2 php docs/scripts/check-claims.php
// or `make docs-claims`.
//
// The adapter section needs the API workspace; it is skipped with a note when
// that has not been installed, so the harness still runs on a bare checkout.

require __DIR__ . '/../../vendor/autoload.php';

$workspace = __DIR__ . '/../.api-workspace/vendor/autoload.php';
$hasWorkspace = is_file($workspace);
if ($hasWorkspace) {
    require $workspace;
}

use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Exception\InvalidCallSpecification;
use Rasuvaeff\Understudy\Exception\VerificationFailed;
use Rasuvaeff\Understudy\Invocation;
use Rasuvaeff\Understudy\Understudy;

use function Rasuvaeff\Understudy\expect;
use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

$pass = 0;
$fail = [];

function claim(string $page, string $text, callable $body): void
{
    global $pass, $fail;

    Understudy::reset();
    try {
        $ok = $body();
        if ($ok === true) { $pass++; return; }
        $fail[] = [$page, $text, is_string($ok) ? $ok : 'returned false'];
    } catch (Throwable $e) {
        $fail[] = [$page, $text, get_class($e) . ': ' . $e->getMessage()];
    }
}

/** Runs $body and reports the class of what it threw, or null. */
function threw(callable $body): ?string
{
    try { $body(); } catch (Throwable $e) { return $e::class; }

    return null;
}

interface Repo
{
    public function find(int $id): ?string;
    public function save(string $v): void;
    public function tag(string $a, int $b): void;
    public function ping(): void;
    public function mode(): string;
    public function wide(string $a, string $b, string $c): void;
    public function many(string ...$rest): void;
}

interface Clock { public function now(): int; }

interface Paginated { public function count(): int; }

interface Holder
{
    public function clock(): Clock;
    public function maybeClock(): ?Clock;
}

interface WithStatic
{
    public static function make(): string;
    public function use(): void;
}

class Real implements Repo
{
    public array $seen = [];
    public function find(int $id): ?string { return 'real:' . $id; }
    public function save(string $v): void { $this->seen[] = $v; }
    public function tag(string $a, int $b): void {}
    public function ping(): void {}
    public function mode(): string { return 'real'; }
    public function wide(string $a, string $b, string $c): void {}
    public function many(string ...$rest): void {}
}

final class Subject
{
    public function __construct(public Repo $repo, public Clock $clock, public int $limit = 5) {}
}

final class Thrower { public function value(): string { throw new RuntimeException('nope'); } }

// ---------------------------------------------------------------- stubbing
claim('stubbing/index', 'returns($a, $b): one per call, last repeats', function (): bool|string {
    $r = Understudy::for(Repo::class);
    when(fn () => $r->mode())->returns('fast', 'slow');

    return [$r->mode(), $r->mode(), $r->mode()] === ['fast', 'slow', 'slow']
        ?: 'got ' . json_encode([$r->mode()]);
});

claim('stubbing/index', 'a later stub for the same call wins', function (): bool|string {
    $r = Understudy::for(Repo::class);
    when(fn () => $r->find(1))->returns('first');
    when(fn () => $r->find(1))->returns('second');

    return $r->find(1) === 'second' ?: 'got ' . var_export($r->find(1), true);
});

claim('stubbing/index', 'an earlier stub stays reachable as a fallback', function (): bool|string {
    $r = Understudy::for(Repo::class);
    when(fn () => $r->find(Arg::any()))->returns('broad');
    when(fn () => $r->find(1))->returns('narrow');

    return [$r->find(1), $r->find(2)] === ['narrow', 'broad'] ?: 'got ' . json_encode([$r->find(1), $r->find(2)]);
});

claim('stubbing/chaining', 'then(): one link per call, last repeats', function (): bool|string {
    $r = Understudy::for(Repo::class);
    when(fn () => $r->mode())->returns('a')->then()->returns('b');

    return [$r->mode(), $r->mode(), $r->mode()] === ['a', 'b', 'b'] ?: 'chain did not repeat its last link';
});

// ---------------------------------------------------------------- matchers
claim('stubbing/matchers', 'Arg::any() matches null', function (): bool|string {
    $r = Understudy::for(Repo::class);
    when(fn () => $r->find(Arg::any()))->returns('hit');

    return $r->find(0) === 'hit' ?: 'any() did not match';
});

claim('stubbing/matchers', 'Arg::int() rejects a numeric string', function (): bool|string {
    $r = Understudy::for(Repo::class);
    when(fn () => $r->save(Arg::int()))->returns(null);
    // save() declares string, so a numeric string is what actually arrives.
    $r->save('5');

    return Understudy::calls(fn () => $r->save(Arg::int())) === [] ?: 'Arg::int() matched the string "5"';
});

claim('stubbing/matchers', 'Arg::which(): a getter that throws is a mismatch, not an error', function (): bool|string {
    $r = Understudy::for(Repo::class);
    $thrower = new Thrower();
    when(fn () => $r->save(Arg::which('value', 'x')))->returns(null);
    $err = threw(static fn () => $r->save('plain'));

    return $err === null ?: "matching raised {$err}";
});

claim('stubbing/matchers', 'a specification stopping early without Arg::rest() is refused', function (): bool|string {
    $r = Understudy::for(Repo::class);
    $err = threw(static fn () => when(fn () => $r->wide('a'))->returns(null));

    return $err !== null ?: 'the short specification was accepted silently';
});

claim('stubbing/matchers', 'Arg::rest() lets a specification stop early', function (): bool|string {
    $r = Understudy::for(Repo::class);
    when(fn () => $r->wide('a', Arg::rest()))->returns(null);
    $r->wide('a', 'b', 'c');

    return count(Understudy::calls(fn () => $r->wide('a', Arg::rest()))) === 1 ?: 'rest() did not match';
});

// ---------------------------------------------------------------- capturing
claim('stubbing/capturing', 'last() on an empty captor raises NothingCaptured', function (): bool|string {
    $c = Arg::captor();

    return threw(static fn () => $c->last()) === 'Rasuvaeff\Understudy\Exception\NothingCaptured'
        ?: 'got ' . var_export(threw(static fn () => $c->last()), true);
});

claim('stubbing/capturing', 'all() on an empty captor answers an empty list', function (): bool|string {
    return Arg::captor()->all() === [] ?: 'all() was not empty';
});

claim('stubbing/capturing', 'a call the other arguments rejected captures nothing', function (): bool|string {
    $r = Understudy::for(Repo::class);
    $c = Arg::captor();
    when(fn () => $r->tag('alpha', $c->capture()))->returns(null);
    $r->tag('beta', 7);

    return $c->all() === [] ?: 'captured from a non-matching call';
});

// ------------------------------------------------------------ expectations
claim('expectations/index', 'times(3) means exactly 3', function (): bool|string {
    $r = Understudy::for(Repo::class);
    expect(fn () => $r->ping())->times(3);
    $r->ping(); $r->ping(); $r->ping(); $r->ping();

    return threw(static fn () => Understudy::verifyAll()) !== null ?: 'four calls satisfied times(3)';
});

claim('expectations/index', 'times(minimum: 2) means EXACTLY 2, not "at least"', function (): bool|string {
    $r = Understudy::for(Repo::class);
    expect(fn () => $r->ping())->times(minimum: 2);
    $r->ping(); $r->ping(); $r->ping();

    return threw(static fn () => Understudy::verifyAll()) !== null
        ?: 'three calls satisfied times(minimum: 2) — it really is an open range';
});

claim('expectations/index', 'times(2, null) is the open range', function (): bool|string {
    $r = Understudy::for(Repo::class);
    expect(fn () => $r->ping())->times(2, null);
    $r->ping(); $r->ping(); $r->ping();

    return threw(static fn () => Understudy::verifyAll()) === null ?: 'times(2, null) rejected a third call';
});

claim('expectations/index', 'an expectation armed after the run counts zero', function (): bool|string {
    $r = Understudy::for(Repo::class);
    $r->ping();
    expect(fn () => $r->ping());

    return threw(static fn () => Understudy::verifyAll()) !== null ?: 'a retrospective expect() passed';
});

// ---------------------------------------------------------------- verifying
claim('expectations/verify', 'verify() defaults to "at least once"', function (): bool|string {
    $r = Understudy::for(Repo::class);
    $r->ping(); $r->ping();

    return threw(static fn () => verify(fn () => $r->ping())) === null ?: 'verify() rejected two calls';
});

claim('expectations/verify', 'verify(times: 2) is exact', function (): bool|string {
    $r = Understudy::for(Repo::class);
    $r->ping(); $r->ping(); $r->ping();

    return threw(static fn () => verify(fn () => $r->ping(), times: 2)) !== null ?: 'times: 2 accepted three calls';
});

claim('expectations/verify', 'verify(minimum: 2) has no upper bound', function (): bool|string {
    $r = Understudy::for(Repo::class);
    $r->ping(); $r->ping(); $r->ping();

    return threw(static fn () => verify(fn () => $r->ping(), minimum: 2)) === null ?: 'minimum: 2 rejected three calls';
});

claim('expectations/verify', 'lastCall() answers null on an empty log', function (): bool|string {
    $r = Understudy::for(Repo::class);

    return Understudy::lastCall(fn () => $r->find(Arg::any())) === null ?: 'lastCall() was not null';
});

claim('expectations/verify', 'didReturn()/returned()/args on a recorded call', function (): bool|string {
    $r = Understudy::for(Repo::class);
    when(fn () => $r->find(123))->returns('x');
    $r->find(123);
    $calls = Understudy::calls(fn () => $r->find(Arg::any()));

    return ($calls[0]->args === [123] && $calls[0]->didReturn() === true && $calls[0]->returned() === 'x')
        ?: 'call log shape differs';
});

// ------------------------------------------------------------------- modes
claim('modes', 'a loose double answers a type-safe default', function (): bool|string {
    $r = Understudy::for(Repo::class);

    return $r->mode() === '' ?: 'got ' . var_export($r->mode(), true);
});

claim('modes', 'a strict double refuses an unconfigured call', function (): bool|string {
    $r = Understudy::for(Repo::class);
    Understudy::strict($r);

    return threw(static fn () => $r->ping()) === 'Rasuvaeff\Understudy\Exception\StrictModeViolation'
        ?: 'got ' . var_export(threw(static fn () => $r->ping()), true);
});

claim('modes', 'a matched expectation satisfies a strict double', function (): bool|string {
    $r = Understudy::for(Repo::class);
    Understudy::strict($r);
    expect(fn () => $r->ping());

    return threw(static fn () => $r->ping()) === null ?: 'strict refused an expected call';
});

// --------------------------------------------------------------- lifecycle
claim('lifecycle/forget', 'a forgotten double raises ForgottenDouble on use', function (): bool|string {
    $r = Understudy::for(Repo::class);
    Understudy::forget($r);

    return threw(static fn () => $r->ping()) === 'Rasuvaeff\Understudy\Exception\ForgottenDouble'
        ?: 'got ' . var_export(threw(static fn () => $r->ping()), true);
});

claim('lifecycle/index', 'idle() is true with no doubles and false with one', function (): bool|string {
    if (Understudy::idle() !== true) { return 'idle() was false on a fresh context'; }
    $r = Understudy::for(Repo::class);

    return Understudy::idle() === false ?: 'idle() stayed true after for()';
});

claim('lifecycle/index', 'scope() returns its callback value and drops the context', function (): bool|string {
    $out = Understudy::scope(static fn () => 42);

    return ($out === 42 && Understudy::idle()) ?: 'scope() leaked or returned wrong';
});

claim('lifecycle/index', 'a scope closes clean over an unsatisfied outer claim', function (): bool|string {
    $outer = Understudy::for(Repo::class);
    expect(fn () => $outer->find(1));

    try {
        Understudy::scope(static function (): void {
            $inner = Understudy::for(Repo::class);
            expect(fn () => $inner->find(2));
            $inner->find(2);
        });
    } catch (VerificationFailed) {
        return 'the scope answered for the enclosing context';
    }

    try {
        Understudy::verifyAll();
    } catch (VerificationFailed) {
        return true;
    }

    return 'the outer claim was settled rather than left standing';
});

claim('stubbing/matchers', 'an inverted range is refused where it is written', function (): bool|string {
    try {
        Arg::int(min: 5, max: 1);
    } catch (InvalidCallSpecification) {
        return true;
    }

    return 'Arg::int(min: 5, max: 1) was accepted';
});

claim('stubbing/matchers', 'a pattern PCRE cannot compile is refused, quietly', function (): bool|string {
    $raised = [];
    set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
        $raised[] = $message;

        return true;
    });

    try {
        Arg::string('/[unclosed');

        return 'Arg::string(\'/[unclosed\') was accepted';
    } catch (InvalidCallSpecification) {
        return $raised === [] ?: 'the refusal raised the warning it exists to prevent';
    } finally {
        restore_error_handler();
    }
});

claim('lifecycle/retention', 'lean(): returned() raises OutcomeUnavailable', function (): bool|string {
    $r = Understudy::for(Repo::class);
    Understudy::lean($r);
    when(fn () => $r->find(1))->returns('x');
    $r->find(1);
    $call = Understudy::lastCall(fn () => $r->find(Arg::any()));

    return threw(static fn () => $call?->returned()) === 'Rasuvaeff\Understudy\Exception\OutcomeUnavailable'
        ?: 'got ' . var_export(threw(static fn () => $call?->returned()), true);
});

claim('lifecycle/retention', 'lean() keeps the call for matching and verify()', function (): bool|string {
    $r = Understudy::for(Repo::class);
    Understudy::lean($r);
    $r->find(1);

    return threw(static fn () => verify(fn () => $r->find(1))) === null ?: 'lean() lost the call itself';
});

// ------------------------------------------------------------------ wiring
claim('wiring', "wire() returns ['sut' => …, 'doubles' => …] keyed by parameter name", function (): bool|string {
    $wired = Understudy::wire(Subject::class);

    return ($wired['sut'] instanceof Subject
        && array_keys($wired['doubles']) === ['repo', 'clock'])
        ?: 'got keys ' . json_encode(array_keys($wired['doubles'] ?? []));
});

claim('wiring', 'a scalar with a default keeps it and gets no double', function (): bool|string {
    $wired = Understudy::wire(Subject::class);

    return $wired['sut']->limit === 5 ?: 'scalar default was not applied';
});

claim('wiring', 'an override does not appear in doubles', function (): bool|string {
    $real = new Real();
    $wired = Understudy::wire(Subject::class, ['repo' => $real]);

    return ($wired['sut']->repo === $real && !array_key_exists('repo', $wired['doubles']))
        ?: 'override leaked into doubles';
});

// -------------------------------------------------------------- forwarding
claim('forwarding', 'forwarding: unconfigured calls run for real and are recorded', function (): bool|string {
    $real = new Real();
    $spy = Understudy::delegate(Repo::class, $real);
    $out = $spy->find(9);

    return ($out === 'real:9' && count(Understudy::calls(fn () => $spy->find(Arg::any()))) === 1)
        ?: 'got ' . var_export($out, true);
});

claim('forwarding', 'a stub wins over the real object', function (): bool|string {
    $spy = Understudy::delegate(Repo::class, new Real());
    when(fn () => $spy->find(9))->returns('stubbed');

    return $spy->find(9) === 'stubbed' ?: 'the real object won';
});

claim('forwarding', 'for($real) does not delegate until forwarding() is called', function (): bool|string {
    $real = new Real();
    $double = Understudy::for($real);
    $before = $double->mode();
    Understudy::forwarding($double);

    return ($before === '' && $double->mode() === 'real') ?: "before={$before}";
});

// ---------------------------------------------------------------- defaults
claim('defaults', 'defaults() supplies an unconfigured return of that type', function (): bool|string {
    $fake = new class implements Clock { public function now(): int { return 7; } };
    Understudy::defaults(Clock::class, static fn () => $fake);
    $wired = Understudy::wire(Subject::class);

    return $wired['sut']->clock instanceof Clock ?: 'clock was not built';
});

claim('defaults', 'a factory of the wrong type raises InvalidDefaultValue', function (): bool|string {
    Understudy::defaults(Clock::class, static fn (): string => 'not a clock');
    $h = Understudy::for(Holder::class);
    $err = threw(static fn () => $h->clock());

    return $err === 'Rasuvaeff\Understudy\Exception\InvalidDefaultValue' ?: 'got ' . var_export($err, true);
});

claim('defaults', 'a registration outranks null on a nullable return', function (): bool|string {
    $fake = new class implements Clock { public function now(): int { return 7; } };
    Understudy::defaults(Clock::class, static fn () => $fake);
    $h = Understudy::for(Holder::class);

    return $h->maybeClock() === $fake ?: 'nullable return answered null despite a registration';
});

claim('modes', 'an unconfigured return that can be doubled becomes a double, one level deep', function (): bool|string {
    $h = Understudy::for(Holder::class);
    $nested = $h->clock();
    if (!$nested instanceof Clock) { return 'no nested double'; }

    return $nested->now() === 0 ?: 'nested double did not answer a default';
});

// ------------------------------------------------------------------ target
claim('doubles/creating', 'calling a static contract method raises InvalidCallSpecification', function (): bool|string {
    $d = Understudy::for(WithStatic::class);
    $err = threw(static fn () => $d::make());

    return $err === 'Rasuvaeff\Understudy\Exception\InvalidCallSpecification' ?: 'got ' . var_export($err, true);
});

claim('doubles/creating', 'several interfaces can be combined in one double', function (): bool|string {
    $d = Understudy::for(Repo::class, Paginated::class);

    return ($d instanceof Repo && $d instanceof Paginated) ?: 'the combined double lost a contract';
});

claim('doubles/creating', 'a userland interface has its return type unified correctly', function (): bool|string {
    $d = Understudy::for(Repo::class, Paginated::class);
    $type = (string) (new ReflectionMethod($d, 'count'))->getReturnType();

    return $type === 'int' ?: "count() came back as {$type}";
});

// A built-in interface carries a TENTATIVE return type, which reflection
// reports separately. The page says the override declares it rather than
// widening to `mixed`; declaring `mixed` is what PHP answers with a
// deprecation notice, so this claim is also what keeps the example on that
// page from emitting one.
claim('doubles/creating', 'a built-in interface keeps its tentative return type', function (): bool|string {
    $double = Understudy::for(Repo::class, Countable::class);
    $type = (string) (new ReflectionMethod($double, 'count'))->getReturnType();

    return $type === 'int' ?: "count() declares {$type}, not int";
});

claim('doubles/creating', 'an enum target is refused', function (): bool|string {
    $err = threw(static fn () => Understudy::for(\Rasuvaeff\Understudy\FailureKind::class));

    return $err !== null ?: 'an enum was accepted as a target';
});

// ------------------------------------------------ accounting and ordering
claim('expectations/nothing-else', 'a when() stub accounts for nothing', function (): bool|string {
    $r = Understudy::for(Repo::class);
    when(fn () => $r->save('x'))->returns(null);
    $r->save('x');

    return threw(static fn () => Understudy::nothingElse($r)) !== null ?: 'a stub accounted for its call';
});

claim('expectations/nothing-else', 'a successful verify() accounts for the calls it claimed', function (): bool|string {
    $r = Understudy::for(Repo::class);
    $r->save('x');
    verify(fn () => $r->save('x'));

    return threw(static fn () => Understudy::nothingElse($r)) === null ?: 'verify() did not account for its call';
});

claim('expectations/nothing-else', 'nothingElse() takes several doubles and names every offender', function (): bool|string {
    $a = Understudy::for(Repo::class);
    $b = Understudy::for(Repo::class);
    Understudy::label($a, 'first');
    Understudy::label($b, 'second');
    $a->save('x');
    $b->save('y');
    try { Understudy::nothingElse($a, $b); } catch (Throwable $e) {
        $m = $e->getMessage();

        return (str_contains($m, 'first') && str_contains($m, 'second')) ?: 'only one double was named';
    }

    return 'nothingElse() passed with two unaccounted calls';
});

claim('expectations/ordering', 'ordered() tolerates unrelated calls in between', function (): bool|string {
    $r = Understudy::for(Repo::class);
    expect(fn () => $r->save('a'))->ordered();
    expect(fn () => $r->save('b'))->ordered();
    $r->save('a');
    $r->ping();
    $r->save('b');

    return threw(static fn () => Understudy::verifyAll()) === null ?: 'ordered() rejected an unrelated call';
});

claim('expectations/ordering', 'verifySequence() rejects the wrong order', function (): bool|string {
    $r = Understudy::for(Repo::class);
    $r->save('b');
    $r->save('a');

    return threw(static fn () => Understudy::verifySequence(
        fn () => $r->save('a'),
        fn () => $r->save('b'),
    )) !== null ?: 'verifySequence() accepted a reversed protocol';
});

claim('expectations/ordering', 'arming a second protocol while one runs is refused', function (): bool|string {
    $r = Understudy::for(Repo::class);
    Understudy::expectSequence(fn () => $r->save('a'), fn () => $r->save('b'));

    return threw(static fn () => Understudy::expectSequence(fn () => $r->ping())) !== null
        ?: 'a second protocol was armed over a running one';
});

claim('expectations/strict-stubs', 'strictStubs fails an unused stub, and the default does not', function (): bool|string {
    $r = Understudy::for(Repo::class);
    when(fn () => $r->find(1))->returns('x');
    if (threw(static fn () => Understudy::verifyAll()) !== null) { return 'the default failed an unused stub'; }

    return threw(static fn () => Understudy::verifyAll(strictStubs: true)) !== null ?: 'strictStubs passed an unused stub';
});

claim('lifecycle/index', 'checkpoint() keeps the double but clears what is settled', function (): bool|string {
    $r = Understudy::for(Repo::class);
    expect(fn () => $r->ping());
    $r->ping();
    Understudy::checkpoint();

    return (Understudy::idle() === false && threw(static fn () => Understudy::verifyAll()) === null)
        ?: 'checkpoint() dropped the double or kept a settled claim';
});

claim('lifecycle/index', 'transcript() renders each call with its outcome', function (): bool|string {
    $r = Understudy::for(Repo::class);
    when(fn () => $r->find(1))->returns('x');
    $r->find(1);
    $t = Understudy::transcript($r);

    return (str_contains($t, 'find(1)') && str_contains($t, 'returned')) ?: 'transcript: ' . trim($t);
});

claim('failure-messages', 'label() names the double in a failure', function (): bool|string {
    $r = Understudy::for(Repo::class);
    Understudy::label($r, 'books');
    expect(fn () => $r->ping());
    try { Understudy::verifyAll(); } catch (Throwable $e) {
        return str_contains($e->getMessage(), '`books`') ?: 'message: ' . $e->getMessage();
    }

    return 'verifyAll() passed with an unmet expectation';
});

// Both reporting paths render the same report. They did not always: verifyAll()
// had a sprintf of its own and showed the summary line alone, so the alias
// table and the argument marks this page is about were missing from every
// failure a runner adapter reported.
claim('failure-messages', 'verify() and verifyAll() both render the call log', function (): bool|string {
    $r = Understudy::for(Repo::class);
    $r->tag('beta', 2);
    $viaVerify = '';
    try { verify(fn () => $r->tag('alpha', 2)); } catch (Throwable $e) { $viaVerify = $e->getMessage(); }

    Understudy::reset();
    $r2 = Understudy::for(Repo::class);
    expect(fn () => $r2->tag('alpha', 2));
    $r2->tag('beta', 2);
    $viaAll = '';
    try { Understudy::verifyAll(); } catch (Throwable $e) { $viaAll = $e->getMessage(); }

    return (str_contains($viaVerify, 'The following calls') && str_contains($viaAll, 'The following calls'))
        ?: 'one of the two paths stopped rendering the log';
});

claim('failure-messages', 'both paths word an uncalled expectation the same way', function (): bool|string {
    $r = Understudy::for(Repo::class);
    expect(fn () => $r->ping());
    $message = '';
    try { Understudy::verifyAll(); } catch (Throwable $e) { $message = $e->getMessage(); }

    return str_contains($message, 'but it was never called') ?: "verifyAll() said: {$message}";
});

// ------------------------------------------------------------- adapters
// These read the satellites out of the API workspace, which a bare checkout
// does not have. Skipped rather than failed there: the harness is about the
// engine's own pages first.
if (!$hasWorkspace) {
    echo "  (adapter claims skipped: run `composer install` in docs/.api-workspace)\n";
} else {
    claim('adapters/testo', 'UnderstudyPlugin takes strictStubs, defaulting to false', function (): bool|string {
        $constructor = (new ReflectionClass('Rasuvaeff\Understudy\Testo\UnderstudyPlugin'))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $param) {
            if ($param->getName() === 'strictStubs') {
                return ($param->isDefaultValueAvailable() && $param->getDefaultValue() === false)
                    ?: 'strictStubs does not default to false';
            }
        }

        return 'no strictStubs parameter';
    });

    claim('adapters/testo', 'UnderstudyInterceptor exists', static fn (): bool =>
        class_exists('Rasuvaeff\Understudy\Testo\UnderstudyInterceptor'));

    claim('adapters/phpunit', 'UnderstudyPHPUnitIntegration is a trait', static fn (): bool =>
        trait_exists('Rasuvaeff\Understudy\PhpUnit\UnderstudyPHPUnitIntegration'));

    claim('adapters/phpunit', 'the trait declares assertPostConditions() and understudyStrictStubs()', function (): bool|string {
        $names = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass('Rasuvaeff\Understudy\PhpUnit\UnderstudyPHPUnitIntegration'))->getMethods(),
        );
        $missing = array_diff(['assertPostConditions', 'understudyStrictStubs'], $names);

        return $missing === [] ?: 'missing ' . implode(', ', $missing);
    });

    claim('adapters/psalm', 'the plugin entry point exists', static fn (): bool =>
        class_exists('Rasuvaeff\Understudy\Psalm\Plugin'));

    claim('adapters/psalm', 'UnderstudyMisuse is the plugin issue type', static fn (): bool =>
        class_exists('Rasuvaeff\Understudy\Psalm\Issue\UnderstudyMisuse'));

    claim('adapters/phpstan', 'every rule extension.neon registers exists', function (): bool|string {
        $neon = (string) file_get_contents(
            __DIR__ . '/../.api-workspace/vendor/rasuvaeff/understudy-phpstan/extension.neon',
        );
        preg_match('/^rules:\n((?:\s+-\s*\S+\n)+)/m', $neon, $matches);
        $missing = [];
        foreach (explode("\n", trim($matches[1] ?? '')) as $line) {
            $class = ltrim(trim((string) preg_replace('/^\s*-\s*/', '', $line)), '\\');
            if ($class !== '' && !class_exists($class)) {
                $missing[] = $class;
            }
        }

        return $missing === [] ?: 'missing ' . implode(', ', $missing);
    });
}

echo "\n";
printf("%d claim(s) verified against the code.\n", $pass);
if ($fail !== []) {
    printf("%d MISMATCH(es):\n\n", count($fail));
    foreach ($fail as [$page, $text, $why]) {
        printf("  %-28s %s\n      -> %s\n", $page, $text, $why);
    }
    exit(1);
}
