# Changelog

## Unreleased

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
