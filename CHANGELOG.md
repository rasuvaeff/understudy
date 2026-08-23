# Changelog

## Unreleased

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
