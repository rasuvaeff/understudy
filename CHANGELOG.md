# Changelog

## Unreleased

- **`Understudy::for()` no longer kills the process on five built-in
  interfaces.** `Throwable`, `UnitEnum`, `BackedEnum`, `DateTimeInterface` and
  `Traversable` walked past every refusal in the factory and were answered by
  the compiler instead — a fatal out of `eval()`, uncatchable by `try` or by an
  adapter, and fatal to the whole suite run rather than to one test. Each is now
  an `UnsupportedTarget` naming the way through. `Iterator`,
  `IteratorAggregate`, `Stringable` and `Countable` keep doubling: it was never
  about being built in.
- **A duplicated contract is accepted rather than fatal.**
  `Understudy::for(A::class, A::class)` — a list assembled programmatically —
  produced the same uncatchable fatal, because `implements A, A` does not
  compile.
- **`#[\SensitiveParameter]` is honoured.** The value of such a parameter is
  rendered as its type — `login('user', string SensitiveParameter)` — in
  failure messages and in `transcript()`, the way PHP redacts it in its own
  stack traces. It used to go into both verbatim, which is to say into a CI log.
- **Three public paths now throw an `UnderstudyError`.** `times(5, 2)`, a
  negative count and `returns()` with no values threw a bare
  `\InvalidArgumentException`, while `UnderstudyError` declares itself
  implemented by every exception this library throws — so a `catch
  (UnderstudyError $e)`, which `llms.txt` recommends, walked past them. The new
  `InvalidSpecificationArgument` extends `\InvalidArgumentException`, so a catch
  by the SPL type keeps working.
- **`Arg::instanceOf()` refuses a class that is not loadable.** It used to
  match nothing, forever, and say so nowhere — the reader saw only "expected …
  but it was never called" and looked for the cause in the subject under test.
- **`NAN` no longer raises a PHP warning while a failure message is rendered.**
  On PHP 8.5 `(string) NAN` warns, from inside the library, during the render of
  a report about a failure — which under `failOnWarning` turns the report into a
  different failure.
- **Control bytes and binary strings are escaped in messages.** A NUL or half a
  broken UTF-8 sequence travelled into the failure text and the transcript as
  the raw byte, breaking the single line the escaping exists to keep. Valid
  multibyte text is untouched.
- `CannotWire` and `InvalidDefaultValue` say "has type `array`" and "produced a
  value of type `array`" instead of "is a `array`".
- Documentation: `checkpoint()` clears only the **settled** calls, not the call
  log (the text promised otherwise, and the code was right); a declared property
  default is kept while a promoted one is not; a built-in interface as a return
  type does not become a nested double; `Arg::string()` uses PCRE semantics for
  `$`; both Security sections say that arguments are printed verbatim except
  sensitive ones. A broken cookbook link in `examples/README.md` and a dead plan
  reference in `docs.yml` are gone, and the committed API pages are regenerated
  from the current `src/` (they were built from a v0.5.0 snapshot).

## 0.7.2 — 2026-09-04

- **Documentation review fixes.** llms.txt no longer claims `bypassFinals()`
  and the runner adapters are «being built next» (both shipped long ago), and
  now lists all four free functions and the `: static` loose-default rule.
  The Prophecy migration table no longer teaches a non-existent
  `expect(…)->never()` — the supported form is `expect(…)->times(0)`.
  `idle()` comments across the READMEs, llms.txt and the guide now say what
  the operation does: it covers every context of the test, not the current
  one. The Performance table's `5.38³` footnote marker was a damaged
  character. `examples/README.md` now documents `case-studies/` and the gate
  that runs it (`make docs-cookbook`). AGENTS.md's dead link to the retired
  site plan replaced with where the decisions live.

## 0.7.1 — 2026-09-04

- **`bypassFinals()` missed a target whose declaration is written in another
  case.** `final class gate` in `namespace App` IS `App\Gate` to PHP —
  `class_exists()` says so, and `bypassFinals(\App\Gate::class)` accepted the
  name and installed the target — but the strip compared both halves with
  `===` and walked past the very declaration it was installed for. The class
  stayed final, with nothing said until `for()` refused it. Both halves are
  compared the way PHP compares them now. Fixes #97.

## 0.7.0 — 2026-09-04

A minor rather than a patch: `Invocation` loses two public properties,
`verify()` refuses argument combinations it used to resolve by precedence, and
`notADouble()` gains a required argument. Composer's caret treats that as
breaking on 0.x, and it is — though every one of them was the engine knowing
something and not saying it.

- **`Invocation::$file` and `$line` are gone.** They were promoted readonly
  properties on an `@api` class that the dispatcher never filled in and
  nothing ever read — public API whose only value was `null`. Filling them
  would mean a `debug_backtrace()` on every dispatched call, which this
  package will not pay for a field nobody asked for.
- **`verify($call, never: true, times: 3)` is refused rather than resolved.**
  `never` used to win and the count beside it was discarded without a word, so
  a test could say two opposite things and pass. Both analyser packages report
  this pairing and both promise a runtime counterpart for every complaint they
  make; this is it, in their wording, so a user does not meet a second
  phrasing of a mistake they have already seen.
- **`verify($call, times: -1)` is refused as the nonsense argument it is.**
  The bounds now go through `Cardinality`, which is what the fluent `times()`
  has always used, so a negative count and a maximum below the minimum are
  caught where they are written. `times: -1` used to become the range
  `[-1, -1]` and fail as an unmet count.
- **A facade method handed something that is not a double names itself.**
  `Understudy::strict(new stdClass())` used to answer "the specification
  closure did not call a method on an understudy" — and `strict()` has no
  closure, so the reader was sent looking for a mistake they had not made.
  `strict`, `lean`, `forwarding`, `label`, `unused`, `nothingElse`,
  `allVerified` and `transcript` each say their own name now, the way
  `forget()` already did. Knowing which facade was called also separates two
  mistakes that used to share one message: an object that never was a double,
  and a double whose context has been reset — the latter now gets the same
  `ForgottenDouble` answer a CALL on that object already got.
- **A loose double answers `: static` with itself.** The receiver IS the
  double and the generated method really does declare `static`, so a fluent
  contract chains without a stub for each link. It used to refuse with
  `NoDefaultValue` — the one return type whose answer needs no invention at
  all. Both READMEs say so, and say why `: self` is a different claim.
- A return-type conflict no longer prints an unresolved `parent`. The message
  paths rendered the type without the declaring class, so a reader on PHP 8.3
  or 8.4 was told a target declares `: parent` and left to work out which
  class that is; 8.5 resolves it in Reflection, which is why it went
  unnoticed. Pinned by a test that fails on 8.3 without the fix.
- `Runtime\Mode` is `@internal`, like the rest of `Runtime\`. It never
  appears in a public signature — `strict()`, `lean()` and `forwarding()` are
  what a user writes — so the `@api` on it promised a contract nobody could
  reach and no document described.
- Two orphaned docblocks removed, and `WhenBuilder` says why it is the one
  class here that is not `final`. `FileWrapper::install()` says that it only
  ever widens, and that a target list added to the global mode changes nothing
  because global already reaches every class it names.

- **A targeted `bypassFinals()` stops tokenising files it has no interest
  in.** The guard was "does the source contain the word `final`", and
  `finally` contains it — 58% of a real vendor tree passes, and in targeted
  mode almost none of it declares anything asked for. A file that never names
  a target's class cannot declare that class, so it never reaches the
  tokenizer: 16 µs against 256 µs on a 41 KB file, or roughly a second of
  tokenising per process. The filter is a NECESSARY condition, which is why it
  is safe — its absence is proof, its presence decides nothing and the
  tokenizer still says.
- **`QueryEquals` keeps building its `ReflectionMethod` per call**, and that
  is the answer rather than an omission: caching the decision per class and
  calling the getter directly measures 94 ns against 104 ns, which is 0.6% of
  the 1.65 µs a dispatch through this matcher costs. The number is in the
  code, so the next audit reads it instead of re-asking.

Fixes #95.

## 0.6.0 — 2026-09-04

A minor rather than a patch: a pair of targets the unifier used to accept is
now refused. Composer's caret treats that as breaking on 0.x, and it is —
though what those targets produced was a double that logged an argument value
neither contract declares.


- **Conflicting parameter defaults across unified contracts rejected the
  target instead of quietly becoming `null`.** `Understudy::for(A::class,
  B::class)` where both declare `tag(string $name, int $weight = 5)` and
  `= 7` rendered `= null` and widened the type to `int|null` to make that
  legal — so an omitted argument was logged as a value neither contract
  declares, and `verify(fn () => $double->tag('alpha', 5))` did not match the
  call `A` would have made. That is the failure `renderDefault()`'s own
  docblock says the unifier refuses; now it does, the way the by-reference
  conflict beside it already did. A default declared in one target and absent
  or required in another is not a conflict and keeps working. Fixes #91.
- **A stub armed with `-0.0` was invisible to a call made with `0.0`.** The
  dispatch index keyed a literal first argument by `serialize()`, which is not
  isomorphic to `===`: `-0.0 === 0.0` is true and `d:-0;` is not `d:0;`. The
  index is only consulted from the second expectation on a method, so an
  unrelated second stub changed the first one's behaviour. Fixes #92.
- `DispatchIndexPropertyTest` gains a second property for identity, over
  literals of every scalar type — the existing one draws `int` only, so no
  float, let alone a signed zero, was ever reached. The pairs a key can get
  wrong in either direction are named `Examples()` rather than left to the
  random phase: both signed zeros, `0` against `0.0` and `'0'`, `''` against
  `null` and `false`, `1` against `true`, and `NAN`, which matches no call
  including itself.
- **The parity matrix gained `ForeignCapture`**: somebody else's zero-argument
  `capture()` written inside a specification. Both analysers must report it,
  and one of them did not — `understudy-psalm` decided a capture by the method
  name and an empty argument list, because its issue hook is handed no resolved
  receiver, and swallowed the diagnostic. Fixed in that package (#18) and
  pinned here, which is what the matrix exists for.
- `perf/README.md` records that the figures were re-verified for the v0.5.0
  tag and left unchanged: three runs a side at the commit they were taken from
  and on the release candidate, with our movement inside the competitors'.

## 0.5.0 — 2026-09-03

A minor rather than a patch: a closing `scope()` verifies less than it did, and
two `Arg::` factories now refuse a configuration they used to accept. Both are
behaviour toward the consumer's own test code, which Composer's caret already
treats as breaking on 0.x.

- **`bin/consumer-smoke` now carries the analyser parity matrix**: eleven
  idioms — our `Arg` unqualified, aliased and fully qualified, the static verb
  form, `rest()` short and leaked, a captor capture in a specification and in a
  real call, a namesake `Arg` under two spellings, and a plain leak — checked
  against one table of expectations by both `understudy-psalm` and
  `understudy-phpstan`. The two answer the same contract by opposite
  mechanisms, and nothing compared them before; the analyser packages' own CI
  runs these legs against their working tree. The phpunit leg gained the
  composition hazard the adapter cannot defend against: a consumer class that
  overrides `assertPostConditions()` without the documented alias loses
  verification silently, and the README's recipe keeps it — both now executed
  rather than described.
- **A closing `scope()` no longer answers for the enclosing context.** It
  verified every live context of the test, so a self-contained nested scope
  failed on a claim the caller had simply not got round to satisfying yet —
  including the recommended use of `scope()` to drop doubles holding OS
  resources before the runner's teardown, which is exactly the position where
  the enclosing expectations are still open. A close now verifies the context
  it opened, and only that one; nothing is settled or forgiven, so
  `verifyAll()`, `checkpoint()` and the runner adapter's teardown still report
  the enclosing claims. A test that relied on a scope surfacing an outer
  failure earlier will now see it at the end of the test instead. (#84)
- **A matcher that could never match is refused where it is written.**
  `Arg::int(min: 5, max: 1)` — and the same shape in `Arg::float()` and
  `Arg::count()` — describes an empty range, and `Arg::string('/[unclosed')` is
  not a pattern PCRE compiles. Both built a matcher that answered "no" to every
  argument, so a typo surfaced as an expectation never met with nothing
  pointing at its cause; the broken pattern also raised PHP's own warning on
  every call from inside the code under test, which is the one thing a matcher
  must not do. Both now raise `InvalidCallSpecification`, and the pattern's
  message carries PCRE's own reason. (#86)
- **Two decisions written down rather than changed.** A predicate handed to
  `Arg::satisfies()` is the test's own code, so an exception in it travels
  instead of being read as a mismatch — unlike `Arg::which()`, which reaches
  into the argument. And `verifySequence()` with no calls asserts that nothing
  happened, where `expectSequence()` refuses an empty protocol; both are now
  stated in the docblocks and pinned by tests.
- The mutation gate quoted in `README.md`, `README.ru.md` and `AGENTS.md` says
  92, which is what `infection.json5` has required since #76.
- Both READMEs and the failure-messages guide say what was only in the 0.1.0
  changelog: the wording of a failure message is not public contract, and
  anything acting on a failure reads `FailureKind` and the readonly fields of
  `VerificationFailure`, which are frozen.

## 0.4.1 — 2026-08-30

- **`verifyAll()` renders the call log when an expectation goes unmet.** Two
  code paths reported the same failure and only one showed the calls:
  `verify()` rendered through `FailureReport`, while the verification sweep
  had a `sprintf` of its own. Since `verifyAll()` is the path every runner
  adapter takes, the alias table and the `*` argument marks — the part that
  says WHICH call differed — were missing from almost every failure anyone
  saw. Both paths now render the same report, the failure carries
  `observedCalls`, and the sentence the two used to word differently ("but it
  was called never") is settled. A test asserting the exact text of an unmet
  expectation may need updating. (#77, #79)
- **Doubling a built-in interface no longer emits a deprecation notice.**
  PHP's own interfaces carry tentative return types, which
  `ReflectionMethod::getReturnType()` reports as null, so
  `Understudy::for(Repo::class, Countable::class)` generated `count(): mixed`
  and PHP answered with `should either be compatible with
  Countable::count(): int`. The unifier reads the tentative type, which
  satisfies the contract exactly and needs no `#[\ReturnTypeWillChange]`. The
  defect was invisible for `ArrayAccess` and `JsonSerializable`, whose
  tentative type is `mixed` anyway. (#78, #80)
- **A documentation site for the whole family**, at
  <https://rasuvaeff.github.io/understudy/>: the guide, migration guides from
  Mockery and PHPUnit, a cookbook of real incidents whose output the build
  diffs against the scripts that produce it, and an API reference reflected
  out of all five packages' `src/` — free functions and analyser rule
  identifiers included, neither of which a class-only reference would show.
  `MIGRATION.md` at the root is generated from the same pages. (#82)
- **The README's Mockery table mapped `->atLeast()->once()` to
  `times(minimum: 1)`, which is not what that does.** `times()` branches on
  the number of arguments it was given, so one argument is an exact count
  however it is spelled; the open range is `times(1, null)`. Both READMEs now
  show all four forms and say why. (#81)
- **The escaped-mutant hunt: 240 → 158, MSI 90.4% → 94.0%, gate raised to 92**
  (the full trajectory and the reason live next to the number in
  `infection.json5`). About sixty targeted tests and fixtures, each written
  from an escaped mutant's diff rather than from the line it sits on:
  constant defaults now assert their rendered SOURCE (`\Cfg::LIMIT`, uppercase
  `SELF::`/`PARENT::` resolved through the declaring hierarchy) where the old
  tests compared values a mutant renders identically; static satisfaction
  gets its satisfied complements (a type-distinct variadic tail, untyped and
  union return contracts); by-reference detection is asserted at every
  parameter position; `FinalStripper` pins the glued-comment and bare
  `final const` boundaries; settle() is pinned to drop satisfied claims and
  keep every stub; Fiber routing is pinned for by-reference returns,
  `callOriginal()` and ordering claims; the structured failure fields
  (`actualCount`, both bounds of `unused()`, `observedCalls` filtering,
  `expectedCalls` as strings, both halves of `allVerified()`) are asserted
  exactly, as are six refusal messages formerly checked by fragments. Three
  more test classes attribute `#[Covers]` they exercised all along. Three
  manual arbitrations confirmed the remainder is dominated by genuine
  equivalents — redundant second guards, set-map values read through
  `array_keys()`, perf fastpaths, warning-only offsets.
- **Honest-coverage sweep after 0.4.0.** Twelve targeted tests kill the
  escaped mutants the new code left behind (hooked-property collection and
  rendering, cross-Fiber property routing, forwarding write-through, captor
  registration, the specification-hole boundary, retired-scope invisibility),
  and three test classes now attribute `#[Covers]` they exercised all along —
  which is what let several of those mutants escape unmapped. A new matcher
  property pins "no matcher throws on a hostile argument, every one describes
  itself" across the whole `Arg::` catalog, `rest()` and a captor's
  `capture()` included.
- `examples/` caught up with 0.4.0: `Arg::rest()` and a typed captor in
  `basic-usage.php`, `delegate()` and `lean()` in `modes.php`, and a new
  `property-hooks.php` (self-skipping on PHP 8.3). The Mockery migration
  table gains the `makePartial()`/`withAnyArgs()`/`Mockery::capture()` rows,
  and three edge behaviours are now stated: a `capture()` in an
  `expectSequence()` step matches without recording, `lean()` cannot release
  the stable slot behind a `&` return, and a `clone` does not carry written
  property values over.

## 0.4.0 — 2026-08-28

Every open issue of the backlog in one release. All additive — nothing
removed, nothing renamed; a minor because `Arg::rest()`, `Arg::captor()`,
`Understudy::delegate()`, `Understudy::lean()` and rendered property hooks
are new public API, and on 0.x that is the boundary Composer's caret already
treats as breaking. The perf ritual ran before the tag: three full-harness
runs a side against the commit the published figures were taken at — the
per-call marginal cost is unchanged (0.90µs), double creation moved +1.4-2.4%
while the competitors moved ±3-4% in the same runs, which is inside the noise
floor.

- **Interface-declared property hooks are rendered, so a modern contract can
  be doubled at all** (rasuvaeff/understudy#36). `public string $name { get; }`
  on an interface — or an `abstract` hooked property on a class — no longer
  refuses the target: the generated class declares the property with the
  dispatcher inside the hook, which no `__get`-based library can do (`__get`
  fires only for an inaccessible property). Reads answer the forwarding
  target's value, else what the code under test wrote (a `{ get; set; }`
  property behaves like a plain one), else the mode's type-safe default —
  `Understudy::defaults()` registrations and the depth-1 nested double
  included. Exactly the declared hooks are rendered: a get-only property
  refuses a write with PHP's own error. A property read is not a call — not
  recorded, not specifiable, not judged by strict mode; stubbing and verifying
  reads is future work by design. Still refused, each with its reason: a
  readonly class target carrying an abstract hook (a hooked property cannot be
  readonly, and a readonly class only extends readonly), and a by-reference
  `&get` hook. Concrete hooks on class targets stay inherited, and on PHP 8.3
  nothing changes — the language cannot express a hooked property there.

- **`Understudy::lean()`: a call log that does not retain returned values**
  (rasuvaeff/understudy#63). The transcript keeps every invocation with its
  outcome until `reset()` — which the runner adapters call *after* the test's
  own teardown, so a value a double returned is still referenced while
  teardown runs. Real incident: a forwarding double returned file streams and
  `FileHelper::removeDirectory()` failed with "Directory not empty" — on
  Windows only, since POSIX unlinks open files. A lean double keeps the
  invocation (method, arguments, sequence), so matching, `verify()`,
  `transcript()` and `nothingElse()` work unchanged, but not the value:
  `Invocation::returned()` raises `OutcomeUnavailable` the way it already does
  for a call that threw. Also caps per-call memory growth in hot loops. The
  retention interaction — and `Understudy::scope()` as the other remedy — is
  now documented in both READMEs and llms.txt.

- **The pre-0.1.0 double-creation regression is now a stated decision, not an
  open question** (rasuvaeff/understudy#56). `perf/README.md` records that the
  ~0.3µs per double bisected to #23 bought bounded registration/reset/verify
  accounting and closed the Fiber hole where an unmet `expect()` in a Fiber
  passed silently — a false pass, the one failure mode a verification library
  must not have. The trade is accepted; reopening it requires the full-harness
  A/B the file describes. No code change.

- **`Arg::captor()`: a typed argument captor** (rasuvaeff/understudy#62). The
  typed replacement for reading `args[N]` out of the call log:
  `$options = Arg::captor(DeliveryOptions::class)`, `$options->capture()` in
  the specification where the argument goes, then `$options->last()` /
  `$options->all()` — typed through the class-string generic, so no
  `instanceof` narrowing ritual at the read site. The typed form matches like
  `Arg::instanceOf()`, the untyped like `Arg::any()`; the value is recorded
  only once the whole specification matched, so a call the other arguments
  rejected captures nothing. Works in `when()`, `expect()` and `verify()`.
  `last()` on an empty captor raises the new `NothingCaptured`; captured
  values are dropped with the context, like the call log.

- **`Understudy::delegate(Contract::class, $real)`: a forwarding double in one
  expression** (rasuvaeff/understudy#61). Builds the double, turns forwarding
  on and returns it — the `for()` + `forwarding()` pair that suites leaning on
  forwarding repeated at every site. The target is validated the way
  `forwarding()` validates it; both existing `forwarding()` forms stay.

- **`Arg::rest()`: "the arguments before me matter, the rest of the arity does
  not"** (rasuvaeff/understudy#60). The one matcher that lets a specification
  stop before the method's required parameters run out —
  `when(fn () => $storage->recordOutcome('svc', Arg::rest()))` instead of an
  `Arg::any()` per remaining parameter. To make the shortened call physically
  possible, every required parameter of a generated method now defaults to an
  internal sentinel; a real call that omits a required argument still fails
  with `ArgumentCountError`, raised by the dispatcher instead of the engine,
  so a double is no more permissive about arity than the contract. A
  specification that stops early *without* ending in `Arg::rest()` is refused
  with the reason — including `Arg::remaining()`/`Arg::none()` in that
  position, which describe a variadic tail, not omitted parameters. Layering
  is untouched: a later, narrower specification still wins over the broad
  prefix stub.

## 0.3.0 — 2026-08-27

- **A `when()` stub and an `expect()` for the exact same call are refused at
  registration** with the new `ConflictingExpectation` (rasuvaeff/understudy#59).
  The two never composed: whichever was declared later took the dispatch, and
  the earlier one silently lost its purpose — the stub's answer replaced by
  the mode default, or the count starved and reported as "called never" about
  a call that did happen. Both orders now fail fast, naming the double, the
  specification and the one-registration idioms (`expect(...)->returns(...)`,
  `when(...)->times(...)`). Dispatch semantics are untouched: two plain stubs
  still layer most-recent-first, and overlapping-but-different specifications
  (a broad fallback under a narrow claim) still compose. Registering over a
  *counted stub* (`when(...)->times(...)`) is refused the same way, for the
  same reason.

## 0.2.0 — 2026-08-27

New verbs and richer failure messages; nothing removed, nothing renamed. A
minor rather than a patch because `expectSequence()` is new public API, and on
0.x that is the boundary Composer's caret already treats as breaking.

- **`Understudy::expectSequence()` / `expectSequence()`: a protocol armed before
  the subject runs.** `ordered()` and `verifySequence()` both answer in
  teardown, where the stack trace points at `verifyAll()` rather than at the
  call that went out of turn. An armed protocol refuses inside the offending
  call, with the subject's own frame on top of the stack. Totality is scoped to
  the doubles the protocol names: on those, a call is the step due or something
  the test configured, and anything else is refused — so a query the subject
  makes between two steps has to be stubbed. Each step is due exactly once, in
  order. Arming is also a claim: `verifyAll()` reports the steps the subject
  never reached, which is what still fails a test whose subject swallowed the
  refusal in a broad `catch`. One protocol at a time, a finished one may be
  replaced, and `checkpoint()` verifies it and then drops it. The failure is a
  `VerificationFailed` carrying `FailureKind::OutOfSequence` — no new kind, so
  an exhaustive `match` on the enum keeps compiling.

- **A strict double says what it refused and what it compared the call
  against.** Naming only the method sent the reader back to a test that *had*
  configured that method — the difference was in the arguments, and the message
  did not carry them. The refusal now renders the call and every expectation
  registered for that method that did not accept it, with each rejecting
  argument marked from the expectation's side, a position the call never
  carried included. Up to five, in the order the dispatcher tried them, then a
  count. With nothing configured for the method there is nothing to compare
  against and the message is unchanged. A matcher asked while the message is
  built is asked defensively: it runs inside the code under test, and one that
  throws counts as one that did not accept rather than replacing the refusal
  with its own exception.
- Fixed a deprecation introduced with object rendering: `SplObjectStorage::contains()`
  is deprecated as of PHP 8.5, and every rendered object emitted a notice.

- **Performance.** Four per-call and per-double costs came off the hot paths:
  the armed-protocol check now sits behind one null check, a context is recorded
  live once where it is created rather than once per double adopted into it, and
  one `ReflectionClass` is built per generated class rather than per double. A
  fifth change — retiring a context by marking it instead of walking its
  doubles — was measured, found to move the cost onto creation (13-23% worse in
  the benchmark) rather than remove it, and reverted; the finding is recorded in
  `AGENTS.md`.
- **`perf/README.md` re-measured on a quiet machine.** The previous figures were
  taken at a commit before 0.1.0 and described no released version. They also
  did not show a creation regression that landed before the first tag and
  shipped in 0.1.0, 0.1.1 and 0.1.2; it is bisected, documented, and still
  present.

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
