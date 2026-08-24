---
name: rasuvaeff-understudy
description: >-
  Write test doubles in PHP with rasuvaeff/understudy — a call-closure API
  where the method and its arguments are given by making the call inside a
  closure, and the double exposes nothing beyond its contract. Use when
  writing or reviewing tests that need a stub, a spy, a mock or a partial, and
  when an AI coding assistant needs to know WHICH verb to reach for
  (when vs expect vs verify), HOW to close a test out (nothingElse vs
  allVerified vs verifySequence), and which of the traps below applies —
  matcher-scoped cardinality, broad-stub ordering, final targets, and the
  contexts a Fiber owns.
---

# understudy

Namespace `Rasuvaeff\Understudy`. PHP 8.3–8.5, `ext-tokenizer`, no runtime deps.

```php
use Rasuvaeff\Understudy\Understudy;
use function Rasuvaeff\Understudy\{when, expect, verify};

$repo = Understudy::for(BookRepository::class);
when(fn () => $repo->find(7))->returns($book);
```

Two rules the whole API rests on:

1. **A call is specified by making it**, never by a method-name string. The
   closure must contain exactly one direct call on a double; anything else
   raises `InvalidCallSpecification`.
2. **The double exposes nothing beyond its contract.** Every operation is a
   static method on `Understudy` or one of the three free functions. There is
   no `->shouldReceive()`, no `->expects()`, nothing to collide with a method
   the contract already has.

## Which verb — the first decision, and the one most often wrong

| You want to say | Verb | Fails when |
|---|---|---|
| "if this is called, answer X" — permission, not a claim | `when()` | never (unless `strictStubs`) |
| "this **must** be called" — a claim, checked for you | `expect()` | the call did not happen as claimed |
| "was this called?" — after the fact, in the test body | `verify()` | asserted immediately |

`expect()` is the reason to use this library: an unmet expectation fails the
test **even if you forget to assert**. `when()` never fails on its own.
`verify()` is for questions you want answered at a specific point.

Do not reach for `verify()` to express a requirement — that is `expect()`.
Do not reach for `expect()` to set up a value the test merely needs — that is
`when()`, and using `expect()` there turns incidental setup into a claim you
will have to maintain.

### Their shapes differ, and it catches people out

```php
expect(fn () => $repo->count())->times(2);        // builder, chained
verify(fn () => $repo->count(), times: 2);        // named arguments
verify(fn () => $repo->ping(), never: true);      // NOT ->times(0)
```

`expect()` returns a builder; `verify()` takes named arguments. Writing
`verify(...)->times(0)` is the single most common first-try mistake.

## Cardinality is scoped to the arguments — the trap that silently weakens a test

`expect()` counts only the calls that match **its own** arguments. A
hand-rolled spy with a `$calls` counter counted every call to the method.

```php
// Was:  Assert::same($spy->calls, 1);
expect(fn () => $reporter->report($throwable, $request));
Understudy::nothingElse($reporter);   // <- the half the counter also asserted
```

Without `nothingElse()`, a second call with different arguments passes. A
migration from a hand-rolled spy that drops this line is a silent weakening,
not a refactor. **Reviewers: check for it every time.**

## Closing a test out

| Question | Call |
|---|---|
| Were my expectations met? | the adapter does it, or `Understudy::verifyAll()` |
| Did anything else happen to this double? | `Understudy::nothingElse($a, $b, …)` |
| Both, for one double | `Understudy::allVerified($double)` |
| Was the whole protocol exactly this, in order? | `Understudy::verifySequence(...)` |
| Was this double untouched? | `Understudy::unused($double)` |

A call is *accounted for* when an `expect()` matched it or a **successful**
`verify()` claimed it. A `when()` stub accounts for nothing — it is
permission, not a description. A failed `verify()` accounts for nothing either.

`expect(...)->ordered()` constrains order relative to other ordered
expectations only; unrelated calls may happen in between. `verifySequence()`
is the tool when the whole protocol matters — it compares double identity too.

## Matchers — `Arg`

`any`, `int(min:, max:)`, `float(min:, max:)`, `bool`, `string(matches:)`,
`same`, `not`, `instanceOf`, `satisfies`, `containing`, `count`, `which`,
`none`, `remaining`.

**Register broad first, specific after.** Matching takes the most recently
registered stub that matches, so a catch-all registered last shadows
everything:

```php
when(fn () => $repo->find(Arg::any()))->returns(null);   // fallback FIRST
when(fn () => $repo->find(7))->returns($book);           // specific AFTER
```

"Earlier stubs remain as fallbacks" means **on argument mismatch**. An
expectation whose count is exhausted keeps answering the matching call — it
does not hand over to an older stub.

Every `Arg::*` returns `mixed`, so passing one where the contract says `int`
is not an IDE type error. A matcher that reaches a real call raises
`MatcherLeaked`.

## Choosing a target

| Target | Call |
|---|---|
| An interface (the default, and the easiest) | `Understudy::for(Contract::class)` |
| Several contracts at once | `Understudy::for(A::class, B::class)` |
| A non-final class | `Understudy::for(SomeClass::class)` |
| A real instance, to spy on | `Understudy::for($real)` |
| …and delegate unmatched calls to it | `Understudy::forwarding($double, $real)` |
| A `final` class | `Understudy::bypassFinals(X::class)` **before it loads** |

Refused, each by name: a `final` class without bypass, a class with a
non-private `final` instance method, an enum, a trait, an internal class, an
anonymous class, a class that is not the first target.

**`bypassFinals()` is order-dependent and process-wide.** It rewrites the
source as `file://` hands it to PHP, so it works only for classes not yet
read from disk — call it from the test bootstrap. A PHAR, an OPcache preload
and a foreign `file://` wrapper are out of reach, and the refusal says which.
Prefer doubling an interface the class implements: it needs no bypass at all.

## Loose defaults — what an unconfigured call answers

Builtins answer with their zero value (`0`, `''`, `[]`, `false`); `?T`
answers `null`; `Generator` answers an empty generator; a doublable contract
becomes a double of its own, **one level deep**.

```php
Understudy::defaults(Clock::class, fn () => new FrozenClock('2026-01-01'));
```

Nearest registration wins by distance in the type graph; an equal-distance
tie raises `AmbiguousDefaultFactory`. Registrations belong to the context and
go with `reset()`.

Note: a **nullable** return answers `null` before the registry is consulted,
so a registration for `Book` has no effect on a method declared `?Book`.

## Building the system under test

```php
['sut' => $checkout, 'doubles' => $doubles] = Understudy::wire(Checkout::class);
```

Reads the constructor only. Object parameters become doubles; a union of
several object types, a scalar without a default, and a by-reference
parameter are refused before the constructor runs.

## Lifecycle

Install an adapter and stop thinking about it:

- Testo — `new UnderstudyPlugin()` in `testo.php`
- PHPUnit / Pest — `use UnderstudyPHPUnitIntegration;`

Without one, call `Understudy::reset()` in your own teardown, always.

`Understudy::scope(fn () => …)` opens a nested context verified on success and
dropped either way. `Understudy::checkpoint()` verifies and clears what is
settled, keeping the doubles — for a test that runs in phases.

**Isolation is per Fiber; accounting is per test.** A Fiber gets its own
recording phase, call log and sequence counter, but `verifyAll()`, `reset()`,
`idle()` and `checkpoint()` span every context the test used. A body run in a
Fiber is still the test's.

## Diagnosing

```php
echo Understudy::transcript($repo);   // every call and its outcome
Understudy::calls(fn () => $repo->find(Arg::any()));       // list<Invocation>
Understudy::lastCall(fn () => $repo->find(Arg::any()));    // ?Invocation
```

`lastCall()` is the null-safe read of the newest matching call — prefer it
over `count($calls) - 1`, which reads as `int<-1, max>` to a static analyser
and fatals on an empty log at runtime.

`transcript()` retains every invocation until `reset()`. Do not drive a double
through an unbounded hot loop when the arguments hold large object graphs.

## Replaced doubles and `strictStubs`

A fixture field reassigned mid-test (`$this->generator = $this->fixedGenerator(...)`)
orphans the first double with its stubs. Under `verifyAll(strictStubs: true)`
that stub fails — about a double the test no longer uses. Say it was replaced:

```php
Understudy::forget($this->generator);   // before the reassignment
```

Anything on a forgotten double afterwards — calls included — raises
`ForgottenDouble`, naming `forget()`. One-way.

## Doubles inside property tests

A property body runs many times (runs, examples, shrinks) inside one test;
the adapter's verify/reset covers the whole property, not one run. Exact
`expect()` cardinality would count every run at once — use a `when()` stub
built inside the body, and assert on the observable outcome. Keep an eye on
the transcript growth: a property is a bounded hot loop, and a per-run object
graph in the arguments multiplies by the run count.

## Checklist before you call a test done

- [ ] Every requirement is an `expect()`, not a `verify()` you might forget.
- [ ] Every replaced spy counter has a `nothingElse()` beside its `expect()`.
- [ ] A double replaced mid-test has `forget()` before the reassignment (or no `strictStubs`).
- [ ] Doubles in a property use `when()`, never `expect()` cardinality.
- [ ] Broad stubs registered before specific ones.
- [ ] `verify(..., never: true)`, not `verify(...)->times(0)`.
- [ ] An adapter is installed, or `reset()` runs in teardown.
- [ ] No `Arg::*` left in a real call — that is `MatcherLeaked`.
- [ ] A static contract method is not being doubled: calling one raises
      `InvalidCallSpecification`. Inject an instance dependency instead.
