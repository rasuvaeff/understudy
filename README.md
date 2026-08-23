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

### Doubling a final class

Somebody else's `final` class, no interface, and no way to change it — that is
what `bypassFinals()` is for:

```php
// In your test bootstrap, before the class is autoloaded.
Understudy::bypassFinals(FinalGate::class);   // one class
Understudy::bypassFinals();                    // every class this process loads
```

It is opt-in because the technique has limits that are better met knowingly than
discovered:

| | |
|---|---|
| Order matters | it works only for a class not yet read from disk; a class is read once per process |
| The process is changed | the class really is not final any more, so reflection in your test sees something production does not |
| `final` methods stay | a final method cannot be overridden either way, so a class carrying one is still refused |
| PHAR and preloaded classes | their source arrives as `phar://`, or before any bootstrap ran, so it never passes through the `file://` wrapper |
| The opcode cache is not a way back | however warm the cache is, and whether or not it holds the bypassed file — Linux keeps it out, Windows does not — the class stays open. Not being cached is a cost where it happens, not a guarantee to rely on |
| Another source transformer | if something else is already rewriting PHP source, understudy refuses rather than replacing it silently; a wrapper that leaves source alone composes and is accepted |

When a class is still final at `for()`, the refusal says which of these it was —
bypass never asked for, asked for other classes but not this one, or asked for
and out of reach — rather than sending you to check the thing that is already
right.

In order of preference: double an interface the class implements; for a value
object, build a real one; introduce an interface. Bypass is the answer when none
of those is available.

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
| Forwarding (`Understudy::forwarding($double, $real)`) | whatever the real instance answers, recorded like any other call |

A loose double never invents a value by running someone else's constructor, and
never hands back an unconstructed instance of a real class. What it can hand
back is another understudy: a return type that can itself be doubled becomes
one, one level deep, which the same test can configure. That double is a
generated stand-in, not the target with its constructor skipped.

One level, and no further — a double created this way refuses to produce
another, so `$a->b()->c()` says so rather than inventing a third collaborator
the test never asked for. Registering a factory for `C` is how you say you meant
it. Where no safe value exists it says so, and names the way out.

### Saying what a default should be

A nested double of `LoggerInterface` answers everything with a default and tells
the test nothing. A `NullLogger` is usually what it wanted:

```php
Understudy::defaults(LoggerInterface::class, fn () => new NullLogger());
Understudy::defaults(ClockInterface::class, fn () => FakeClock::frozen());
```

The nearest registration wins, measured as distance in the type graph: an exact
match first, then the closest registered ancestor. Two ancestors the same
distance away raise `AmbiguousDefaultFactory` rather than letting whichever was
registered first decide — a tie has no order a reader could predict. A factory
that produces the wrong type raises `InvalidDefaultValue`.

Registrations belong to the current context: sibling Fibers do not see each
other's, and `Understudy::reset()` drops them with the test. Register them in a
per-test fixture rather than once for a whole suite.

### Wiring a subject

```php
['sut' => $service, 'doubles' => $d] = Understudy::wire(CatalogService::class);

/** @var Repository $repository */
$repository = $d['repository'];
when(fn () => $repository->find(1))->returns($book);

Assert::same($service->lookup(1), $book);
```

`wire()` reads the constructor and nothing else: no container, no property
injection, no setters. A unit test cares about the collaborators the class
itself asks for.

| Constructor parameter | What it gets |
|---|---|
| a class or interface | a double, returned in `doubles` under the parameter name |
| a nullable object | a double — `null` is something the test can ask for explicitly |
| an intersection | one double of both contracts |
| a union of several object types | refused: picking one would be a guess |
| an object that cannot be doubled, with a default | its own default, applied by PHP |
| a scalar with a default | the declared default, and no double |
| a scalar without one | refused, naming the override to pass |
| a variadic tail | left empty; inventing entries would invent collaborators |
| a by-reference parameter | refused — overrides are values, and passing one would promise a reference semantics `wire()` does not have |

`overrides: ['name' => $value]` replaces one dependency with a real instance or a
double you built yourself; those are yours already, so they do not appear in
`doubles`. Every refusal happens before the constructor runs, so a wrong type is
reported by `wire()` rather than as a `TypeError` from inside the subject.

A variadic tail takes a list, and every element is checked against the declared
type before the constructor runs:

```php
['sut' => $service] = Understudy::wire(TaggedService::class, ['tags' => ['a', 'b']]);
```

Anything that is not a list — a bare value, a string-keyed array — is refused by
name, as is an element of the wrong type. Filling a tail this way means the
parameters before it are passed positionally, so an omitted optional one has its
declared default materialized; that is the one place `wire()` evaluates a
default rather than letting PHP apply it.

### Forwarding to a real object

```php
$real = $container->get(CacheInterface::class);
$spy = Understudy::for(CacheInterface::class);
Understudy::forwarding($spy, $real);

when(fn () => $spy->get('key'))->throws(new PoolOverload());
```

Everything the test did not configure runs for real and is recorded; `get('key')`
throws. The target has to satisfy every contract the double stands in for, or it
is refused.

`Understudy::for($real)` is the shorthand for a non-final class: it builds a
double of that object's class and remembers the object, but keeps answering with
defaults until `Understudy::forwarding($double)` turns delegation on. Wrapping
something is not the same as delegating to it. A final class is refused — its
class is already loaded, so the double cannot keep the concrete type you are
holding.

Inside an answer, one call can go through on its own:

```php
when(fn () => $spy->get('key'))
    ->answers(fn (Invocation $call) => strtoupper((string) $call->callOriginal()));
```

Five things are worth knowing before relying on it:

- **Only the call at the boundary is recorded.** If the real method calls
  another method on itself, that happens inside the real object. Understudy
  proxies an object; it does not instrument one.
- **A `: never` method reaches the real implementation.** Its throw lives
  there, and a forwarding double has something that can answer for itself.
- **An understudy is not a valid target.** Forwarding to one — itself included
  — sends every call back into a dispatcher, and an unmatched one keeps coming
  back until the stack runs out.
- **A by-reference argument is the caller's variable.** A forwarded method
  writes to it, and the call log keeps both readings — what was passed and what
  it became — so a verification still sees the value the caller handed over.
- **A fluent method comes back as the double.** When the real instance returns
  itself, the double is returned instead, so a chain stays doubled. A `static`
  method that returns a *different* instance of the real class is refused —
  that object is not a double, and returning it would break the override's own
  `: static`.

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
