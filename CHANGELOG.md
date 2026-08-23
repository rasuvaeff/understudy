# Changelog

## Unreleased

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
