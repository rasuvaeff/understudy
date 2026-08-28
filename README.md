# rasuvaeff/understudy

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/understudy/v)](https://packagist.org/packages/rasuvaeff/understudy)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/understudy/downloads)](https://packagist.org/packages/rasuvaeff/understudy)
[![Build](https://github.com/rasuvaeff/understudy/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/understudy/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/understudy/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/understudy/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/understudy/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/understudy/php)](https://packagist.org/packages/rasuvaeff/understudy)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[Русская версия](README.ru.md)

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

## Migrating from Mockery

No aliases and no converter — the table maps the verb you know to the shape
here. Two rows are traps, marked ⚠.

| Mockery | Understudy | Notes |
|---|---|---|
| `Mockery::mock(BookRepository::class)` | `Understudy::for(BookRepository::class)` | |
| `$mock->shouldReceive('find')` | `when(fn () => $mock->find(...))` | a real call; no method-name string |
| `->once()` / `->twice()` / `->times(3)` | `expect(fn () => ...)->times(3)` | an `expect()` is checked by `verifyAll()` / the adapter |
| `->atLeast()->once()` | `expect(...)->times(minimum: 1)` | |
| `->andReturn($book)` | `->returns($book)` | |
| `->andReturnUsing(fn ...)` | `->answers(fn (Invocation $i) => ...)` | arguments come from `$i->args` |
| `->andThrow(new NotFound())` | `->throws(new NotFound())` | |
| `->with(123, Mockery::any())` | inside the closure: `find(123, Arg::any())` | |
| `Mockery::on(fn ($x) => ...)` | `Arg::satisfies(fn ($x) => ...)` | |
| `$mock->shouldNotHaveReceived('save')` | `Understudy::unused($mock)` | |
| `$mock->shouldHaveReceived('save')` | `verify(fn () => $mock->save(...))` | after the fact; add `nothingElse()` — see below |
| `Mockery::close()` | adapter's `reset()`, or your own teardown | |
| ⚠ `->shouldReceive(...)->once()` used as setup | `when(...)->returns(...)` | a `when()` is permission, not a claim — if you only needed a value, `expect()` would make incidental setup a failing test |
| ⚠ a spy counting every call | `expect()` + `Understudy::nothingElse($mock)` | `expect()` counts only calls matching **its** arguments; without `nothingElse()` a second call with different arguments passes — a hand-rolled counter caught it, the migration must not lose it |

## Performance

Against Mockery 1.6.15, Prophecy 1.26.1 and PHPUnit 12.5.33 on PHP 8.5.6.
Filtered means, three runs; understudy is the baseline. Full methodology, raw
tables and the environment in [perf/README.md](perf/README.md).

| | understudy | Mockery | Prophecy | PHPUnit |
|---|---|---|---|---|
| build a double (1-method contract) | **2.06µs** | +216% | +683% | +155%¹ |
| build a double (8-method contract) | **2.06µs** | +217% | +641% | +158%¹ |
| stub: build, stub, one call, tear down | 10.6µs | +17% | +76% | **−17%**¹ |
| mock: build, expect, call, verify | 12.8µs | +4% | +128% | **−27%**² |
| marginal cost of one call to a stub | 0.86µs | 1.61µs | 1.51µs | **0.69µs**¹ |
| added to process start (cold) | **1.00×** | 1.50× | 4.96× | 5.38׳ |
| retained per live double | **467–482 B** | 513 B | ~8.5 KB | ~1.25 KB |

¹ `createStub()` ² `createMock()` ³ a ratio rather than milliseconds: cold start
moves far more between runs than its ratios do.

Understudy builds doubles about two and a half times cheaper than the next
fastest, and starts a process in a fifth of the added time. It does **not** win
everywhere: PHPUnit is ahead on both stub and mock scenarios end to end — it
dispatches a call in 0.69µs against understudy's 0.86µs and no longer pays
enough at build time to make up for it.

**Building a double costs more than it did**, 2.06µs against 1.28µs in the
figures published with 0.1.x. Those figures were taken at a commit *before*
0.1.0 and described no released version: a regression landed between them and
the first tag, and has been shipping since. It is bisected and documented in
[perf/README.md](perf/README.md); an attempt to remove it moved the cost
elsewhere and was reverted.

These numbers are informational and gate nothing. Regenerate them with `make
perf` before quoting them anywhere.

## Requirements

- PHP 8.3 – 8.5
- `ext-tokenizer`

No runtime dependencies beyond that (`ext-mbstring` is not needed — failure
messages count characters through PCRE, which cannot be disabled).

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
method, an enum, a trait, an internal class, an anonymous class, any class
that is not the first target, and any contract declaring an abstract property
hook — an interface property, or an `abstract` one on a class: this engine
intercepts calls, and reading a property is not one. A double that cannot
intercept every method would
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

A later stub for the same call wins; earlier ones stay reachable as fallbacks
when their arguments do not match. An exhausted call-count expectation keeps
answering the matching call, so use a non-overlapping matcher when a broad
fallback should handle later calls.

| Matcher | Matches |
|---|---|
| `Arg::any()` | anything, including `null` |
| `Arg::int(min:, max:)` | an `int` in range — a numeric string does not match |
| `Arg::float(min:, max:)` | a `float` in range — an `int` does not match |
| `Arg::string(matches:)` | a string, optionally against a PCRE pattern |
| `Arg::bool()` | a boolean |
| `Arg::same($v)` | strict identity; for objects, the same instance |
| `Arg::not($v)` | negates a literal or another matcher |
| `Arg::allOf(...)` | everything the operands accept; an operand is a matcher or a literal |
| `Arg::anyOf(...)` | anything at least one operand accepts, so `anyOf('draft', 'review')` reads as a set |
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

A stub and an expectation for the **exact same call** do not stack, and both
orders are refused at registration with `ConflictingExpectation`: whichever
was declared later would take the dispatch, silently discarding the stub's
answer or starving the expectation's count. Say both things about one call in
one registration — `expect(...)->returns(...)`, or `when(...)->times(...)` —
and declare a repeated count once, with `times()`. Overlap is not equality:
a broad fallback stub under a narrower expectation is still the documented
layering.

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
Understudy::nothingElse($repository, $clock, $mailer);   // across several doubles
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
accounts for nothing either. `nothingElse()` takes any number of doubles: one
line closes out the whole test, and a failure names every offender rather
than stopping at the first.

`expect(...)->ordered()` constrains the ordered expectations relative to each
other; unrelated calls may happen in between. When the whole protocol matters,
`verifySequence()` is the tool. It compares the double identity as well as the
method and arguments, even when several doubles implement the same contract.
`allVerified()` checks ordered expectations too.

### Failing at the call that broke the order

Both tools above are retrospective: the exception is raised in teardown, and the
stack trace points at `verifyAll()` rather than at the call that went out of
turn. `expectSequence()` arms the protocol *before* the subject runs, so the
refusal happens inside the offending call and the subject's own frame is on top
of the stack:

```php
Understudy::expectSequence(
    fn () => $repository->begin(),
    fn () => $repository->save($book),
    fn () => $repository->commit(),
);

$service->handle($command);   // fails here, on the call that broke it
```

```text
Understudy `BookRepository` received a protocol call out of turn: step 2 of 3 was expected to be `save(App\Book#1 {title: 'Dune'})`.

The call was:
    commit()

The protocol is:
    1. begin()
    2. save(App\Book#1 {title: 'Dune'})   <- due here
    3. commit()
```

| | |
|---|---|
| **Scope** | the doubles the protocol names. A double it never names is invisible to it |
| **On a named double** | the call is the step due, or something the test configured — anything else is refused |
| **Each step** | due exactly once, in order. `ordered()` is the tool for a relative order that tolerates repeats |
| **Unfinished** | arming is also a claim: `verifyAll()` reports the steps the subject never reached |
| **One at a time** | arming a second protocol while one is still running is refused; a finished one may be replaced |
| **`checkpoint()`** | verifies the protocol with everything else, then drops it — it belongs to the phase that declared it |

The price of the second row is deliberate: a query the subject makes between two
steps — `$repository->find(7)` between `begin` and `save` — has to be stubbed
with `when()`. Without it the protocol cannot tell "not part of this" from "you
got the order wrong", and guessing would put the failure back in teardown, which
is what arming exists to avoid.

A subject with a broad `catch` can swallow the refusal. That is why arming is a
claim as well as a guard: the test still fails, in teardown, with the step it
stopped at.

### Phases, scopes and transcripts

```php
Understudy::checkpoint();                       // verify, then forget what is settled
$result = Understudy::scope(fn () => ...);      // nested context, verified on success
echo Understudy::transcript($repository);       // every call and its outcome
Understudy::idle();                             // true when the context holds no doubles
```

`transcript()` retains every invocation until `reset()` or `checkpoint()`.
Avoid unbounded hot loops through a double when the arguments or results hold
large object graphs; use a real fake for load-sized workloads.

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

```php
$last = Understudy::lastCall(fn () => $repository->find(Arg::any()));

$last?->args;   // the newest matching call, null when there was none
```

`lastCall()` is the null-safe replacement for `count($calls) - 1`: an empty
log has no last element, and static analysis cannot prove otherwise, so the
index arithmetic reports `int<-1, max>` before the test even runs.

### Retiring a replaced double

```php
Understudy::forget($replaced);
```

For the double a test built and then replaced — `$this->generator =
$this->fixedGenerator('other')` leaves the first one behind, still holding its
stubs. Under `verifyAll(strictStubs: true)` that stub is a failure about a
double the test no longer uses; `forget()` retires it, so verification,
accounting and reset stop seeing it. Calling anything on the object afterwards
— or asking about its calls — fails with `ForgottenDouble`, which names
`forget()` rather than sending you looking for a `reset()` you never wrote.
One-way, like every other form of forgetting here.

### Modes

| Mode | Unmatched call answers with |
|---|---|
| Loose (default) | a type-safe default: `null`, `0`, `''`, `[]`, an empty generator … |
| Strict (`Understudy::strict($double)`) | an immediate failure naming the method, the call, and what did not accept it |
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

A registration outranks `null` on a nullable return: a method declared
`?ClockInterface` answers with the registered clock, because saying what the
type should be means it there too. Without a registration such a method is
still `null`.

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

`Understudy::delegate()` is that pair in one expression: it builds the double,
turns forwarding on and hands the double back —

```php
$spy = Understudy::delegate(CacheInterface::class, $real);
```

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

An object argument is matched by identity, so two instances never match however
equally they read — and the message has to be able to show which of the two
reasons it was:

```text
Understudy `BookRepository` expected `save(App\Book#1 {title: 'Dune'})` to be called
exactly 2 times, but it was called 1 time.

The following calls to `save` were made during this test:
    save(App\Book#1 {title: 'Dune'})
    save(*App\Book#2 {title: 'Dune'}*)
```

`#1` and `#2` are aliases numbered within one message, in order of first
appearance: the same instance keeps one number wherever it appears, so the log
line above says "this is the object you named" and the marked one says "this is
a rebuilt copy". They are not object ids — an id is reused after a collection,
and the same failing test would print different numbers on different runs.

The braces list **public** properties, up to five, at the same depth budget the
rest of the message uses. Nothing is called to render them: an object that keeps
its state behind getters renders as its alias alone, because running a getter to
print a message would run the code under test at the worst possible moment.

A strict double refuses at the call rather than in teardown, and says what it
compared the call against:

```text
Understudy `BookRepository` is strict and received an unexpected call to `tag()`.

The call was:
    tag('beta', 1)

Nothing configured for `tag` accepted it:
    tag(*string(matches: /^a/)*, *2*)

Configure it first: when(fn () => $double->tag(...))->returns(...)
```

The marks are read from the expectation's side: each one is an argument that
rejected this call, including a position the call never carried. Everything
configured for that method is listed — the dispatcher's own order, up to five,
then a count — because a stub that could never have matched is often exactly the
one the test meant to write. When nothing at all is configured for the method,
there is nothing to compare against and the message stays the single line naming
it.

### Cleaning up

```php
Understudy::reset();
Understudy::idle();   // true when the current context holds no doubles
```

The [understudy-testo](https://github.com/rasuvaeff/understudy-testo) and
[understudy-phpunit](https://github.com/rasuvaeff/understudy-phpunit) adapters
verify and reset for you after every test; without one, call `reset()` in your
own teardown. Isolation and accounting are different things: each Fiber gets
its own recording phase, call log and sequence counter, but `verifyAll()`,
`reset()`, `idle()` and `checkpoint()` cover every context the test put
understudies in. A body that runs in a Fiber is still the test's, and an
adapter asks about the test from wherever it stands.

### Using Pest

Pest already owns the global `expect()` function, so importing understudy's
setup verb collides with it. Import the function under another name:

```php
use function Rasuvaeff\Understudy\expect as expectCall;

expectCall(fn () => $books->find(7));
```

or use the collision-free static form everywhere:

```php
Understudy::expect(fn () => $books->find(7));
```

`when()` and `verify()` are globally free and need no alias.

## Security

Understudy generates a class per set of contracts and evaluates it once per
process. It never loads code from user input, never touches the filesystem, and
holds all state in `WeakMap`s keyed by the double object — never by
`spl_object_id()`, which PHP reuses after collection.

It is a development dependency. Do not install it in production.

## Examples

Runnable scripts live in [examples/](examples/) — one per concept: the three
modes, `wire()`, ordering and protocol verification, the defaults registry, and
reading a failure as data. Each checks itself and exits non-zero on a mismatch,
so `bin/package-audit` runs them as a gate rather than linting them.

## The understudy family

| Package | What it is |
|---|---|
| **rasuvaeff/understudy** *(this package)* | The engine: doubles, matchers, expectations, verification. |
| [rasuvaeff/understudy-testo](https://github.com/rasuvaeff/understudy-testo) | Testo adapter — verification and reset around every test. |
| [rasuvaeff/understudy-phpunit](https://github.com/rasuvaeff/understudy-phpunit) | PHPUnit and Pest adapter — the same, through a trait. |
| [rasuvaeff/understudy-psalm](https://github.com/rasuvaeff/understudy-psalm) | Psalm plugin — matcher-aware specifications and misuse diagnostics. |
| [rasuvaeff/understudy-phpstan](https://github.com/rasuvaeff/understudy-phpstan) | PHPStan extension — the same for PHPStan, plus its own rules. |

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
