# Changelog

## Unreleased

- **An object argument renders as an alias and its public state**, so the `*`
  that marks a differing argument points at something the reader can see:
  `save(App\Book#1 {title: 'Dune'})` against `save(*App\Book#2 {title:
  'Dune'}*)` says the call was made with a rebuilt copy rather than the
  instance the expectation named. Aliases are numbered within one message in
  order of first appearance — not object ids, which are reused after a
  collection and would print differently on each run — and the same instance
  keeps one number everywhere in that message. The braces list public
  properties only, up to five, at the message's existing depth budget: nothing
  is called to render them, so an object keeping its state behind getters
  renders as its alias alone. Failure `summary` text changes; the structured
  fields of `VerificationFailure` do not.

## 0.1.2 — 2026-08-26

- Recorded why eleven `FileWrapper` methods are excluded from mutation, next to
  the exclusion itself. The text is the one written when the list was
  introduced and dropped later with the gate's rationale block; a silent
  exclusion cannot be told apart from one made to get the gate green.
- `AGENTS.md` now carries the perf ritual: re-measure `perf/` on the commit the
  current figures came from before comparing a release candidate, because
  nothing in CI defends those numbers.

- **The release workflow waits for the matrix build instead of judging it
  mid-flight.** A tag pushed right after the merge arrived while master's own
  build was still running, and the guard read a `null` conclusion as a failed
  one — so `v0.1.1` published to Packagist but its GitHub Release had to be
  created by re-running the workflow. No effect on the package itself.

- **`Arg::allOf()` and `Arg::anyOf()`.** The matcher algebra had negation and
  nothing to negate against: `not()` composed, but two conditions on one
  argument needed a hand-written `satisfies()` closure, which then had to carry
  its own description or render as `satisfies(…)` in every failure message. An
  operand is a matcher or a literal, the same pair `not()` takes, so
  `anyOf('draft', 'review')` reads as a set and
  `allOf(instanceOf(Book::class), which('getTitle', 'Dune'))` reads as a
  conjunction — both describing themselves in full when an expectation fails.
  A combinator with no operands, and one holding a tail matcher, are refused
  with the reason rather than silently matching everything.

## 0.1.1 — 2026-08-25

- **The distributed archive no longer carries the feasibility spikes.**
  `.gitattributes` listed every other development directory as
  `export-ignore` but not `spikes/`, so 24 scratch scripts from milestone 0
  travelled into every install. They stay in the repository, where CI runs
  them; they are simply no longer part of what `composer require` downloads.

- **A target declaring an abstract property hook is refused, not fatal.** An
  interface property (`public string $name { get; }`, PHP 8.4+) or an
  `abstract` one on a class is an abstract member the generated class would
  have to implement, and it cannot — this engine intercepts calls, and reading
  a property is not one. Left unimplemented, PHP refused the class from inside
  `eval()` with a fatal error nothing could catch. It is now an
  `UnsupportedTarget` naming the property and its hooks, alongside the other
  refusals that happen before a line is generated.

## 0.1.0 — 2026-08-25

- **Structured failures.** `VerificationFailed::failures()` answers a
  `list<VerificationFailure>` — one record per failed claim, each carrying
  its `FailureKind` (`UnmetExpectation`, `StrictStubUnused`, `OutOfOrder`,
  `OutOfSequence`, `UnaccountedCalls`, `UnusedDouble`), the double's label,
  the call specification, the claimed bounds, the actual count, the observed
  calls as `Invocation` records, and its own rendered summary. The exception
  message is exactly the summaries joined with a blank line — there is no
  path where the two disagree. For tooling that acts on a failure rather than
  printing it: runner adapters, IDE plugins, report aggregators. The readonly
  fields of `VerificationFailure` and `FailureKind` are frozen public API
  from v0.1.0; renaming, removing or retyping one is a major-version change.
- **Golden files for rendered reports.** Multi-line failure messages moved to
  `tests/fixtures/messages/*.txt`, read through
  `Support\GoldenMessage::read()`: a wording review now reads a `.txt` diff
  instead of PHP string concatenation. The convention is written into
  AGENTS.md; single-line messages stay inline.
- **A migration table for Mockery users** in both READMEs — no aliases and no
  converter, by policy: migration help belongs in documentation. The two rows
  that bite carry the dogfooding lessons: a spy counter counted every call,
  an `expect()` counts only calls matching its arguments (so every migrated
  counter needs `nothingElse()`), and incidental setup written as
  `shouldReceive(...)->once()` becomes `when()`, not `expect()`.
- AGENTS.md now states the module boundaries (a matcher depends on nothing
  but leaf formatting; only `Runtime` and the facade see everything) and the
  four-file walkthrough for adding an `Arg::*` matcher.

- **`Understudy::lastCall(callable $call): ?Invocation`** — the newest
  recorded call matching the specification, or null when there was none. The
  null-safe replacement for reading `count($calls) - 1` out of `calls()`:
  an empty log has no last element, and Psalm reports the index arithmetic
  as `int<-1, max>` before the test even runs. Found by dogfooding the
  migrated `yii3-correlation-id` suite, where the hand-written fake's
  null-safe `handledRequest` property had no direct counterpart.
- **`Understudy::forget(object $double): void`** — retires a double on
  purpose. For the double a test built and then replaced, still holding its
  stubs: under `verifyAll(strictStubs: true)` that stub is a failure about a
  double the test no longer uses. Verification, accounting and reset stop
  seeing a forgotten double; any call on it — or any question about its
  calls — fails with `ForgottenDouble`, whose message names `forget()`
  rather than sending the reader looking for a `reset()` they never wrote.
  One-way, like every other form of forgetting here. Also found by the same
  dogfooding.
- `calls()` already answers `list<Invocation>`; a dogfooding review
  suspected otherwise, and the suspicion is recorded here so the next
  reviewer does not re-check it: the Psalm findings that prompted it came
  from `count($calls) - 1`, which `lastCall()` removes.

- **`ext-mbstring` is no longer required.** The only runtime use was counting
  characters while truncating an argument for a failure message; PCRE (`/./us`)
  does the counting now — it is a core extension and cannot be disabled — with
  a byte fallback for strings that are not valid UTF-8. The package installs
  on a stock `php:8.3-cli` image, where mbstring is absent.

- **A registered loose default now outranks `null` on a nullable return.**
  `Understudy::defaults(Book::class, …)` had no effect on a method declared
  `?Book`: the resolver answered `null` before the registry was reached, and
  the same happened inside a union carrying a `null` branch. A registration is
  the test saying what a type should be, and `?Book` is still a Book when
  there is one. Without a registration a nullable return is `null` as before.
- **Verification and teardown now span every context the test used, not only
  the caller's.** A body run in a Fiber owns a context of its own, and an
  adapter asks about the test from wherever it stands — never the same place
  under Testo, where `#[RunInFiber]` puts the pipeline in one Fiber and the
  assert collector opens a second around the body. An unmet `expect()` inside
  such a test kept the suite green. Isolation is unchanged: a Fiber still has
  its own recording phase, call log and sequence counter.
- `Understudy::idle()` answers for the whole test, and `reset()` drops every
  context the test used — including when it is itself called from inside a
  Fiber, which is the shape the runners actually have.
- Fixed: a DNF return type of two intersections, `(A&B)|(C&D)`, was cut in
  half before it was split, and the corrupted type reached the user in the
  error message. Parentheses now come off each branch, and a union of nothing
  but intersections answers with the first one instead of refusing a type the
  engine can build.
- `bypassFinals()` no longer breaks `flock()`, `ftruncate()`, `stream_select()`
  or `file_put_contents(..., LOCK_EX)` for files opened after it: the wrapper
  implements `stream_lock`, `stream_truncate` and `stream_cast`.
- Dispatch is flat in the number of expectations registered for a method
  (800 stubs went from 64us per call to 1.3us), and a method with a single
  expectation answers from a lookup and nothing else, so the common case is
  unchanged.
- `Understudy::idle()` — whether the current context holds no doubles. Runner
  adapters use it as an integration guard: a context that is not idle when the
  next test begins means some earlier cleanup never ran.
- `Understudy::nothingElse()` accepts any number of doubles. A test that used
  several can now close them out in one line — `nothingElse($repo, $clock,
  $mailer)` — and a failure reports every double with unaccounted calls
  instead of stopping at the first.
- A model-based property over the ledger lifecycle — stub/expect → dispatch →
  verify → checkpoint — driven by `Gen::commands()` + `StateMachine`. Any
  random interleaving of configuration, dispatch and verification commands has
  to keep the double's answers, its call log and its accounting in lock-step
  with a pure model, including most-recent-first matching, claim accounting,
  verify marking, `nothingElse()` accounting and checkpoint settling. Four
  pinned examples carry the layerings that were wrong before: a newer claim
  shadowing an older stub, checkpoint survival of stubs, accounting through an
  explicit verify, and a violated claim failing both checks.
- The comparative benchmarks are re-measured after the dispatch work, and both
  READMEs carry the new figures. Per-call cost went from 1.75µs to 0.82µs and
  building a double from 2.08µs to 1.28µs; PHPUnit improved by more, and is now
  ahead of understudy end to end on both the stub and the mock scenario rather
  than only per call. Per-double memory grew from ~350 B to 435–450 B — 32 of
  those bytes are the second, reverse-ordered expectation list that made the
  dispatch cheap, and the trade is written down next to the number.
- `make perf` pins the container to six cores and raises its CPU share. The
  environment the old figures quoted was produced by flags that lived outside
  the repository, which is why they are replaced rather than compared against.
- The refusal to double a `final` class says which of three situations it is:
  bypass was never enabled, it was enabled for other classes but not this one,
  or it was asked for and could not reach the source. The last one names the
  reason — read out of a PHAR, already loaded before the wrapper went in, no
  source file at all. Saying "bypass is not enabled" while it was enabled sent
  the reader to fix the thing that was already right.
- The bypass acceptance matrix now runs the environments it was always meant
  to: a warm cross-process opcode cache, a coverage driver, a PHAR, an OPcache
  preload, a competing final-stripper on `file://`, and a wrapper that leaves
  source alone. Two of them are new facts rather than new tests — a warm
  opcode cache does not reseal a bypassed class, whether or not the file lands
  in the cache at all — which differs by platform; and a wrapper that does not rewrite PHP source composes and is accepted,
  which is the boundary the narrow refusal was always drawing. The suite also
  runs once more under an authoritative Composer classmap, which resolves a
  class to a path out of a static map and includes it without stat'ing first —
  a different route to the same read, and one the wrapper has to be on too.
- `for()` on an abstract class no longer dies on an abstract static. A static
  the target leaves unimplemented — its own, or one an interface declares and
  the abstract class never fills in — has nothing for the generated subclass to
  inherit, so it is declared there like any other contract member. It was being
  treated as inherited instead, and PHP answered the generated class with a
  fatal error naming the missing method: not an exception, so nothing could
  report it. Calling it still refuses with the usual `InvalidCallSpecification`;
  a static has no instance state to dispatch.
- `wire()` accepts an override for a variadic tail. It takes a list, every
  element is checked against the declared type, and anything else is refused by
  name before the constructor runs. Filling a tail passes the parameters before
  it positionally, which is the one place `wire()` materializes a declared
  default rather than leaving it to PHP.
- `wire()` checks an override against a union or intersection parameter instead
  of leaving it to the constructor, and accepts an `int` where a `float` is
  declared — PHP widens one to the other at the call boundary even under
  `declare(strict_types=1)`, so refusing it would refuse a call the subject
  would have taken.
- A class static method that an interface target also declares is checked for
  the whole contract — visibility, parameter types and variance, arity, optional
  and variadic parameters, by-reference on both sides — and not only for its
  return type. The generated class inherits that static, and PHP rejects the
  class outright when the inherited declaration does not satisfy the interface;
  a fatal error is not something a test can catch.
- A default factory that answers with a double whose context was torn down now
  says so, naming the contract, instead of handing the double on to fail later
  on its first method call.
- The mutation gate goes back to 92. The static-contract check is branch-dense
  reflection glue, and covering it honestly raised the denominator faster than
  the tests raised the numerator: the run measures 93.1% locally, three mutants
  above a 93 gate, on a mutant count that differs from CI's by dozens. The
  reason is written into `infection.json5` next to the number.
- Reading a file through a `Bypass\FileWrapper` nobody installed no longer
  installs it. `withoutWrapper()` restored and re-registered unconditionally, so
  one such read made the wrapper `file://`'s owner for the rest of the process
  — every read after it transformed by something the process never asked for.
  The guard existed and was lost when rector turned the method non-static.
- `url_stat()` is quiet unconditionally, like `stream_open()`: a `false` is the
  answer, and a warning raised inside a wrapper is one the caller cannot
  suppress.
- The mutation gate rises from 92 to 93, the first move upward, with what the
  pass found written into `infection.json5` — including why 95 is no longer the
  right target for an engine that now contains a tokenizer and a stream
  wrapper.
- `Understudy::bypassFinals()` lifts `final` off a class so it can be doubled —
  one class by name, or every class the process loads from then on. It is opt-in
  because the technique has limits worth meeting knowingly: it works only for a
  class not yet read from disk, it changes what reflection reports for the rest
  of the process, and it cannot reach a PHAR or a preloaded class. `final` on
  methods is never lifted, so a class carrying one is still refused.
- A `final` target now says what to do about it, in order of preference:
  double the interface, build a real value object, enable bypass before the
  class loads, or introduce an interface.
- `bypassFinals()` refuses rather than pretending: a class already loaded, a
  name that turns out to be an enum or an interface, and a `file://` that
  something else is already transforming.
- A Windows job joins CI. The bypass path stands in front of `file://`, and
  paths, separators and symlink privileges are where the platforms disagree.
- The mutation gate moves from 94 to 92, for the reasons written into
  `infection.json5`. Both drops so far are debt with an address, and PR #13
  raises it back.
- The mutation gate moves from 95 to 94. That is debt with an address, not a
  judgement about how well the engine is tested: 61 of the 102 surviving
  mutants are in `TargetUnifier`'s return-type machinery, milestone-1 code whose
  survivors have grown with the denominator as later milestones added mutants.
  The plan carries the row for the pass that pays it back.
- A method declared `&method()` now returns a reference into a stable slot the
  double's state owns, one per method, so a mutation through it survives to the
  next read. It used to return a reference to a local, which the next call
  replaced and through which nothing could persist. The slot is seeded by the
  mode's own answer and then kept — a loose default recomputes an empty value
  every time, and writing that back would undo what the caller wrote — while a
  configured answer still replaces it.
- `Invocation::callOriginal()` delegates with the arguments the caller still
  holds, so a real method can write through a by-reference parameter. It used to
  pass the log's reading of them, which is a copy.
- The call log keeps both readings of a by-reference argument:
  `Invocation::args` is what the call received, `Invocation::argsAfter()` what it
  became once answered. Taken only for methods that declare such a parameter, so
  every other call pays nothing, and taken whether the call returned or threw.
- `Understudy::defaults(Contract::class, $factory)` says what a loose double
  should hand back for a contract, instead of a nested double that answers
  everything with a default and tells the test nothing. The nearest registration
  wins, measured as distance in the type graph, so the answer does not depend on
  registration order; an equal-distance tie raises `AmbiguousDefaultFactory` and
  a wrong-typed result raises `InvalidDefaultValue`. Registrations belong to the
  context and are dropped by `reset()`.
- A return type that can itself be doubled now becomes one — a double of its
  own, one level deep, adopted into the context that owns the outer double
  rather than whichever Fiber happened to be running. It used to raise
  `NoDefaultValue`. The depth limit is enforced: a double created this way
  refuses to produce another, so a chain of implicit collaborators says so
  instead of growing silently. A registered factory still answers at any depth.
- The loose-default lookup asks the registry only when something was registered.
  Reaching for `class_exists()` first autoloads, and an autoloader round trip on
  every unmatched call cost about half the dispatch time — the comparative
  benchmark caught it against the recorded baseline; no test would have.
- `Understudy::wire(Sut::class)` builds a real subject with an understudy for
  every constructor dependency and hands back both. It reads the constructor and
  nothing else. Object parameters become doubles, an intersection becomes one
  double of both contracts, a scalar with a default keeps it; a union of several
  object types, a scalar without a default, a by-reference parameter, an
  inaccessible constructor and a non-concrete subject are each refused by name,
  before the constructor runs. A parameter that has a default is omitted from
  the call so PHP applies it — reading it would run `= new Foo()` during wiring
  — and an object that cannot be doubled falls back to its own default rather
  than refusing the whole subject.
- Forwarding. `Understudy::forwarding($double, $real)` delegates every call the
  test did not configure to a real instance and records it like any other. The
  target must satisfy every contract the double stands in for. `for($real)`
  builds a double of that object's class and remembers the object without
  turning delegation on — wrapping is not delegating — and refuses a final
  class, whose concrete type a double cannot keep.
- `Invocation::callOriginal()` delegates a single call from inside `answers()`,
  whatever the mode. With no target it raises `OriginalCallUnavailable` rather
  than reaching for the parent implementation over a constructor that never ran.
- A by-reference parameter is collected as a reference, so a forwarded method
  can write back to the caller's variable — the one thing declaring `&`
  promises. It used to be copied on the way into the dispatcher.
- Forwarding records the boundary call only: a real method calling itself is not
  visible, because understudy proxies an object rather than instrumenting one.
  A fluent method that returned the real instance comes back as the double, so a
  chain stays doubled; a `static` method returning a different instance of the
  real class raises `OriginalReturnTypeViolation`. An understudy is refused as a
  forwarding target: every call would come straight back to a dispatcher, and an
  unmatched one would keep coming back until the stack ran out.
- Class targets. `Understudy::for()` accepts a class as its first target, with
  interfaces after it: the constructor and destructor never run, public and
  protected methods are dispatched, private and static ones are left to the
  target, writable public properties start at an empty value of their type, and
  a `readonly` target produces a `readonly` double. `clone` now yields a double
  of its own — same contracts, no expectations, no call log — instead of an
  object the runtime had never heard of.
- A target that cannot be doubled faithfully is refused before anything is
  generated, with the reason and the alternative: a `final` class, a non-private
  `final` instance method, an enum, a trait, an internal or anonymous class, or
  a class after the first target.
- Parameter defaults are reproduced instead of approximated. A class constant is
  rendered through its declaring class, an enum case as itself, and an object
  default from the parameter's own source rendering — `new Foo(1)` and
  `[new Foo(1)]` alike — which also avoids running the constructors that
  `getDefaultValue()` would have. Refusal is narrow: a source naming `self`,
  `static` or `parent`, which would resolve against the generated class. A
  default is never silently replaced with `null`, as it used to be — that made
  the double answer something the contract never promised.
- Comparative benchmark harness in `perf/` — understudy against Mockery,
  Prophecy and PHPUnit's `MockObject`, plus cold-start and retained-memory
  measurements. A separate Composer project, `export-ignore`d from the
  distribution, so none of those libraries reach this package's dependencies.
  Both READMEs carry the resulting table; `perf/README.md` carries the method,
  including why the number of doubles built per timed iteration is part of it.
- Multi-target unification: a variadic tail declared by reference in one target
  and by value in another now names both targets in the rejection instead of
  reporting the same one twice.
- Initial engine: interface doubles generated from Reflection, the sentinel
  recording mechanism behind the call-closure API, `when()` with
  `returns()`/`throws()`/`answers()`, `verify()` with count bounds,
  `Understudy::calls()`, `unused()`, `label()`, loose and strict modes,
  type-safe loose defaults, recorded call outcomes, and failure messages that
  mark the argument that differed.
- Expectation ledger with `expect()`, cardinality, chained actions, ordered
  expectations, `nothingElse()`, `allVerified()`, exact cross-double
  `verifySequence()`, transcripts, checkpoints, and nested scopes.
- Fiber-local runtime contexts with owner-routed normal calls, context-bound
  configuration and verification, scoped-double invalidation, and reset of
  only the current execution context.
- Compatible multi-interface signature unification, including contravariant
  parameters, covariant and synthesised intersection return types,
  deterministic primary-interface parameter names, and safe handling of
  `mixed` and static contract methods.
