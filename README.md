# rasuvaeff/understudy

> **Pre-release.** The API is not stable until `v0.1.0`. See
> [CHANGELOG.md](CHANGELOG.md) for what has landed so far.

Test double library for PHP where the call you configure is a **real call**:

```php
when(fn () => $repository->find(123))->returns($book);
```

No method-name strings, so refactoring and IDE navigation work without a
plugin, and a typo in a method name cannot happen. No service methods on the
double either — every one of them would be a name the doubled contract can no
longer use.

> Using an AI coding assistant? [llms.txt](llms.txt) is a compact API reference
> written for it.

## Why another one

| | Understudy | Mockery / PHPUnit / double |
|---|---|---|
| Specifying a call | a real call in a closure | a method-name string |
| Members added to the double | none | `shouldReceive`, `expects`, `allows`, … |
| Test runner | any (thin adapters) | tied to PHPUnit/Pest, or none |
| Fibers | one context per fiber | shared static state |

The call-closure form comes from [MockK](https://mockk.io) (Kotlin),
[FakeItEasy](https://fakeiteasy.github.io) and [moq](https://github.com/moq/moq)
(C#), and [mocktail](https://pub.dev/packages/mocktail) (Dart). No PHP library
had it.

## Performance

Against Mockery 1.6.15, Prophecy 1.26.1 and PHPUnit 12.5.33 on PHP 8.5.6.
Medians after outlier filtering; understudy is the baseline. Full methodology,
raw tables and the environment in [perf/README.md](perf/README.md).

| | understudy | Mockery | Prophecy | PHPUnit |
|---|---|---|---|---|
| build a double (1-method contract) | **2.08µs** | +355% | +1013% | +304%¹ |
| build a double (8-method contract) | **2.32µs** | +341% | +954% | +278%¹ |
| stub: build, stub, one call, tear down | **14.7µs** | +45% | +125% | +27%¹ |
| mock: build, expect, call, verify | **18.0µs** | +30% | +175% | +16%² |
| marginal cost of one call to a stub | 1.75µs | 2.58µs | —³ | **1.08µs**¹ |
| added to process start (cold) | **3.9ms** | 7.3ms | 16.5ms | 17.0ms |
| retained per live double | **~350 B** | 513 B | ~8.5 KB | ~1.25 KB |

¹ `createStub()` ² `createMock()` ³ too unstable to quote — Prophecy's per-call
path allocates enough that garbage collection, not the call, dominates the
measurement.

Understudy builds doubles roughly four times cheaper than the next fastest, and
holds a live double in a third to a twenty-fifth of the memory. It does **not**
win everywhere: PHPUnit dispatches a stubbed call in 1.08µs against understudy's
1.75µs, so past roughly thirty calls to the same stub a PHPUnit test is the
cheaper one.

These numbers are informational and gate nothing. Regenerate them with `make
perf` before quoting them anywhere.

## Requirements

- PHP 8.3 – 8.5
- `ext-mbstring`

No runtime dependencies beyond that.

## Installation

```bash
composer require --dev rasuvaeff/understudy
```

## Usage

### Creating a double

```php
use Rasuvaeff\Understudy\Understudy;

$repository = Understudy::for(BookRepository::class);
```

`for()` returns the contract's own type, so your IDE and static analyser treat
`$repository` as a `BookRepository`. Several interfaces can be combined:

```php
$double = Understudy::for(BookRepository::class, Countable::class);
```

Understudy unifies compatible signatures across those interfaces: parameter
types are widened, return types use the narrowest compatible declaration or a
synthesised interface intersection, and named arguments follow the first
(primary) interface. Static contract methods exist on the generated class so
the interface can be implemented, but calling one raises
`InvalidCallSpecification`: a static call has no double instance to own its
state.

A class can be the first target, with interfaces after it:

```php
$repository = Understudy::for(DoctrineBookRepository::class, Countable::class);
```

What a class double does and does not do:

| | |
|---|---|
| the target's constructor | never runs — the double is built without it, so no side effect of construction reaches your test |
| public and protected methods | overridden and dispatched; a protected one shows up in the transcript and under strict mode, but PHP's own visibility keeps it out of a setup closure |
| private and static methods | untouched — the target keeps them, because there is no instance state to intercept |
| the destructor | replaced with an empty one, so nothing is torn down that was never built |
| writable public properties | start at an empty value of their type; object-typed, hooked, `final`, `readonly` and `private(set)` ones are left uninitialized, and reading one raises PHP's own error |
| `clone` | produces a double of its own: same contracts, no expectations, no call log, owned by the context that cloned it |

A `readonly` target produces a `readonly` double, which PHP requires and which
costs nothing — the double declares no properties of its own.

Some targets are refused before anything is generated, each with the reason and
what to do instead: a `final` class, a class with a non-private `final` instance
method, an enum, a trait, an internal class, an anonymous class, and any class
that is not the first target. A double that cannot intercept every method would
run the target's real code against an object whose constructor never ran, which
is worse than not building it at all.

Parameter defaults are reproduced rather than approximated: a class constant is
rendered through its declaring class, an enum case as itself, and an object
default from its own source expression — `new Stamp(7)` and `[new Stamp(7)]`
alike — which is never evaluated while the double is generated. A default whose
source names `self`, `static` or `parent` refuses the target, because those
resolve against the generated class and would answer something the contract
never promised.

`bypassFinals()`, which lifts the first two, is being built next.

### Stubbing

```php
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Invocation;

use function Rasuvaeff\Understudy\when;

when(fn () => $repository->find(123))->returns($book);
when(fn () => $repository->find(404))->throws(new NotFound());
when(fn () => $repository->find(Arg::any()))->answers(
    fn (Invocation $call) => new Book(title: (string) $call->args[0]),
);

// One value per call, then the last one repeats.
when(fn () => $repository->mode())->returns('fast', 'slow');
```

A later stub for the same call wins; earlier ones stay reachable as fallbacks,
so a broad `Arg::any()` stub can sit underneath a specific one.

| Matcher | Matches |
|---|---|
| `Arg::any()` | anything, including `null` |
| `Arg::int(min:, max:)` | an `int` in range — a numeric string does not match |
| `Arg::float(min:, max:)` | a `float` in range — an `int` does not match |
| `Arg::string(matches:)` | a string, optionally against a PCRE pattern |
| `Arg::bool()` | a boolean |
| `Arg::same($v)` | strict identity; for objects, the same instance |
| `Arg::not($v)` | negates a literal or another matcher |
| `Arg::instanceOf($class)` | an instance of the class or interface |
| `Arg::satisfies($fn)` | whatever the predicate accepts |
| `Arg::containing($entries)` | an array holding these entries and possibly more |
| `Arg::count(minimum:, maximum:)` | an array or `Countable` of that size |
| `Arg::which($method, $value)` | an object whose getter answers this value |
| `Arg::none()` | an empty variadic tail — last argument only |
| `Arg::remaining()` | the whole variadic tail, any length — last argument only |

The type matchers are deliberately strict: `Arg::int()` rejects `'5'`, and
`Arg::float()` rejects `1`. A matcher pins the declared type as much as the
value, which is the point in a codebase that runs with `strict_types`.

`Arg::which()` calls only a public, non-static method that needs no arguments.
A getter that throws counts as a mismatch, never as an error — matching runs
while the code under test is executing, and a matcher must not be the thing
that breaks it.


### Expecting a call

```php
use function Rasuvaeff\Understudy\expect;

expect(fn () => $repository->save($book));            // exactly once
expect(fn () => $repository->count())->times(1, 3);   // a range

Understudy::verifyAll();
```

`expect()` states how often a call must happen and `verifyAll()` checks it. A
`when()` stub is permission rather than a claim — `->times(2)` turns it into
one. `verifyAll(strictStubs: true)` additionally fails a stub that was never
used.

An expectation needs no `returns()`: counting and answering are separate
concerns, so the mode's type-safe default supplies the value, and a matched
expectation satisfies a strict double because the call was expected.

Pest has a global `expect()` of its own — import this one as `expect as
expectCall`, or call `Understudy::expect()`.

### Chaining behaviour

```php
when(fn () => $breaker->call($operation))
    ->returns('ok')
    ->then()->throws(new ConnectionLost());
```

One link per call, and the last link keeps answering once the chain runs out.

### Verifying

```php
use function Rasuvaeff\Understudy\verify;

verify(fn () => $repository->save($book));                 // at least once
verify(fn () => $repository->save($book), times: 2);       // exactly twice
verify(fn () => $repository->save($book), minimum: 2);     // no upper bound
verify(fn () => $repository->ping(), never: true);

Understudy::unused($repository);                           // nothing at all
```

Every double records every call, so verification never has to be set up in
advance.

### Has everything been described?

```php
Understudy::nothingElse($repository);   // every call was accounted for
Understudy::allVerified($repository);   // expectations met AND nothing else
Understudy::verifySequence(             // the exact protocol, across doubles
    fn () => $repository->begin(),
    fn () => $repository->save($book),
    fn () => $repository->commit(),
);
```

A call counts as accounted for when an `expect()` matched it, or a
**successful** `verify()` claimed it. A `when()` stub accounts for nothing —
it is permission, not a description of what happened — and a failed `verify()`
accounts for nothing either.

`expect(...)->ordered()` constrains the ordered expectations relative to each
other; unrelated calls may happen in between. When the whole protocol matters,
`verifySequence()` is the tool. It compares the double identity as well as the
method and arguments, even when several doubles implement the same contract.
`allVerified()` checks ordered expectations too.

### Phases, scopes and transcripts

```php
Understudy::checkpoint();                       // verify, then forget what is settled
$result = Understudy::scope(fn () => ...);      // nested context, verified on success
echo Understudy::transcript($repository);       // every call and its outcome
```

`scope()` returns whatever its callback returns, and drops the nested context
either way — a failure inside is never replaced by a teardown error. A double
created in a scope is invalid after that scope closes. Configuration and
verification must run in the context that owns the double; normal calls may be
made from another Fiber and are still recorded in the owner's log.
`checkpoint()` keeps the understudies, their modes and their labels while
clearing what the current phase has settled.

### Reading the call log

```php
use Rasuvaeff\Understudy\Arg;

$calls = Understudy::calls(fn () => $repository->find(Arg::any()));

$calls[0]->args;          // [123]
$calls[0]->didReturn();   // true
$calls[0]->returned();    // the value it answered with
$calls[1]->thrown();      // the throwable, if it threw
```

`null` is a valid return value, which is why the outcome is asked about
(`didReturn()`) rather than inferred from the value.

### Modes

| Mode | Unmatched call answers with |
|---|---|
| Loose (default) | a type-safe default: `null`, `0`, `''`, `[]`, an empty generator … |
| Strict (`Understudy::strict($double)`) | an immediate failure naming the method |

A loose double never invents a value by running someone else's constructor and
never hands back an object whose constructor was skipped. Where no safe value
exists it says so, and names the way out.

### Failure messages

```text
Understudy `BookRepository` expected `tag('alpha', 2)` to be called exactly 1 time,
but it was never called.

The following calls to `tag` were made during this test:
    tag(*'beta'*, 2)
```

The asterisks mark the argument that differed — borrowed from
[NSubstitute](https://nsubstitute.github.io). `Understudy::label($double, '…')`
names a double when several of the same contract are in play.

### Cleaning up

```php
Understudy::reset();
```

Adapters for Testo and PHPUnit will call this for you; until they exist, call
it in your own teardown. Reset drops only the current execution context; it
does not erase sibling Fiber contexts.

## Security

Understudy generates a class per set of contracts and evaluates it once per
process. It never loads code from user input, never touches the filesystem, and
holds all state in `WeakMap`s keyed by the double object — never by
`spl_object_id()`, which PHP reuses after collection.

It is a development dependency. Do not install it in production.

## Examples

Runnable scripts live in [examples/](examples/).

## Development

```bash
make build          # validate, normalize, require-checker, cs, psalm, test
make cs-fix
make psalm
make test
make mutation       # infection, gate at 85% MSI
make release-check

make perf-install   # once: the comparative benchmark harness in perf/
make perf           # against Mockery, Prophecy and PHPUnit
make perf-cold      # cold start, one process per double
make perf-memory    # bytes retained per live double
```

Or through Docker directly:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

`spikes/` holds the feasibility fixtures the design rests on; `bash
spikes/run.sh` runs them under any PHP 8.3+ binary.

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
