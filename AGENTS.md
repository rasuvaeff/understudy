# AGENTS.md — understudy

Guidance for AI agents working on this package. Read before changing code.

## What this is

`rasuvaeff/understudy` is a test double library for PHP 8.3+ whose defining
trait is that a configured call is a **real call**: `when(fn () =>
$repo->find(123))->returns($book)`. There are no method-name strings and no
service methods on the double itself.

Public API lives in `Rasuvaeff\Understudy`: the `Understudy` facade, the free
functions `when()`/`verify()` in `src/functions.php`, `Arg`, `Invocation`,
`Outcome`, `WhenBuilder`, and the exceptions under `Exception\`. Everything
under `Codegen\`, `Runtime\`, `Expectation\`, `Defaults\` and `Matcher\` is
`@internal`.

The design and its milestones live in the monorepo at
`_plans/UNDERSTUDY-PLAN.md`. `spikes/` holds the executable feasibility
fixtures the design rests on — they are not tests of this package's API, and
they must keep passing on PHP 8.3/8.4/8.5.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **A double exposes nothing beyond its contract.** Every member added to a
   generated class is a name the doubled interface can no longer use. New
   operations go on the `Understudy` facade or into a free function — never
   onto the double. This is the reason the library exists; a change that
   breaks it is not a trade-off, it is a different library.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- **A class not named by any `#[Covers]` is invisible to mutation testing, even
  when every test drives it.** `TargetUnifier` compiles every double in the
  suite and was claimed by one test class, so Infection never used the rest to
  kill its mutants. Attribution is a claim about what a test exercises, and it
  has to be made; coverage does not infer it. Fixing it took Mutation Code
  Coverage to 100% — and raised the denominator, which is why honest attribution
  can make the percentage fall before it rises.
- **Rector can quietly undo a guard.** `withoutWrapper()` lost its
  `self::$registered` check when `LocallyCalledStaticMethodToNonStaticRector`
  turned the method non-static, and the defect shipped. After a `rector:fix`,
  read the diff for what moved, not only for what compiles.

- **The strip removes the `final` token and the one space after it, never a
  newline.** Dropping whitespace that holds a newline would move every line
  below it, and a stack trace or coverage report one line off is worse than a
  double space.
- **Nothing in `src/` may write to STDERR, under any mutation.** One line there
  makes Infection abandon the whole run. It is not enough for the code to be
  quiet: `stream_open()` reporting errors only when `STREAM_REPORT_ERRORS` was
  set is the documented wrapper contract, and Infection flipped the comparison
  so that every call reported. The wrapper is therefore silent unconditionally —
  a `false` is the signal, and PHP raises its own warning at the call site,
  where a caller's `@` can suppress it.
- **A wrapper method must not install the wrapper as a side effect.**
  `withoutWrapper()` restores and re-registers only when the wrapper is actually
  registered; without that guard, a unit test calling `stream_open()` on a bare
  instance would leave `file://` ours for the rest of the process.
- **A warm opcode cache does not reseal a bypassed class, and that is the
  claim — not "the file is never cached".** The narrower statement was measured
  on Linux (766 files cached, the bypassed target not among them) and then
  generalised from one platform; Windows CI reported the same file *cached*.
  What holds everywhere is the behaviour, so that is what the scenario asserts.
  Asserting on what an opcode cache chose to keep is asserting on its
  implementation.
- **A scenario answers in its last line, not its whole output.** With a
  coverage driver loaded PHP warns on stdout that JIT is disabled before any
  of our code runs, and a harness reading the whole stream compares that
  warning against the expected answer.
- **The foreign-wrapper refusal is narrow on purpose, and a fixture that
  simulates one must be token-aware.** It asks whether the source read back is
  the source on disk, so it catches another `final`-stripper and lets a wrapper
  that leaves PHP source alone compose. A test fixture stripping with
  `str_replace('final class ', ...)` also rewrites the string literal the check
  looks for, and the check then reads its own marker back out of the rewrite —
  it passes, and the passing looks exactly like a working bypass.
- **`bypassFinals()` can only classify a type that is already loaded.** Asking
  the autoloader would load the very class the caller wants opened, and a class
  is read from disk once. An unloaded enum or interface therefore passes and is
  refused later by `for()`, which is the moment it matters.
- **Bypass claims are tested in a process each.** A class loads once, so two
  scenarios in one process prove only whichever ran first — and coverage cannot
  see into a subprocess, which is why the decisions are unit tested separately
  from the end-to-end claims.
- **Assert on declarations, not on phrases.** `str_contains($source, 'final
  class')` matched the words "final classes" in a fixture's own docblock and
  turned three passing tests red for the wrong reason.

- **The mutation gate is 92, and every move it has made is written down next
  to the number.** 95 -> 94 -> 92 -> 93 -> 92, and `infection.json5` says why
  each time. `TargetUnifier` still carries most of the surviving mutants: it is
  reflection glue whose branches are combinations of what a signature can be,
  so honest `#[Covers]` attribution grows the denominator about as fast as new
  tests grow the numerator. Read the reasons before moving the number, and add
  one when you do — a gate whose verdict flips on which PHP built the run is
  measuring the environment, not the suite.
- **The only `&` in the package is in generated code, and that is deliberate.**
  Psalm cannot follow a reference into an object property and says so by name;
  the way out is not a suppression but moving the reference to where Psalm never
  looks. `Runtime::referenceSlot()` hands back a plain `ReferenceSlot` object by
  value, and the generated method returns `$slot->value` from its `&` signature.
  Zero `@psalm-suppress`, zero baseline, zero `issueHandlers` — keep it that way.
- **`DoubleState::hasActionFor()` must walk expectations exactly as the
  dispatcher does.** Same accessor, same order, stop at the first match and
  answer with *its* `hasAction()`. Asking "does some matching expectation have
  an action" disagrees with dispatch precisely when a newer
  `expect(...)->times(1)` shadows an older stub, and the by-reference slot is
  then replaced by a value dispatch never returned.
- **A snapshot has to reach nested references.** `array_map()` detaches only the
  top level, and a by-reference parameter is often an array with a `&$row`
  inside it. The recursion is depth-capped because `$a[] = &$a` is legal PHP.
- **`Invocation` carries the live arguments separately from the logged ones.**
  `callOriginal()` delegates with the live ones — a real method is expected to
  be able to write through a by-reference parameter — while `args` stays the
  reading taken before the call.
- **A by-reference argument is snapshotted on both sides, and only where it can
  move.** `MethodSignature::$hasReferenceParameters` gates it: taking two
  readings of every call would cost the whole suite for a rare case.

- **Never autoload on the dispatch path.** `class_exists($name)` does, by
  default, and an autoloader round trip per unmatched call cost about half the
  dispatch time — caught by `make perf` against the recorded baseline, not by
  any test. `class_exists($name, autoload: false)` is a hash lookup and is
  fine; that is what guards the registry's reflection, and it is load-bearing,
  because a builtin type name would otherwise reach `new ReflectionClass()`.
  The registry itself is asked only when something was registered.
- **The defaults registry lives in the context, and the depth-1 double is
  adopted into the *owner* context.** A nested double handed to a call made from
  another Fiber must belong to whoever owns the outer double, or the test that
  configured it can neither configure nor verify what it got back.
- **Depth stops at one, and it is enforced, not described.** A double created
  by a loose default is marked `nested`, and asked for another one it refuses.
  The comment saying "depth stops here" was true of intent and false of code
  until review caught it: nothing stopped `$a->b()->c()` from inventing a third
  collaborator. A registered factory still answers at any depth — that is the
  test saying it meant this one.
- **`wire()` evaluates a constructor parameter default in exactly one place.**
  A parameter that has one is omitted from the call and PHP applies it —
  reading it would run `= new Foo()` during wiring. The exception is an
  override that fills a variadic tail: Reflection forbids positional arguments
  after named ones, so every parameter before the tail has to be passed
  positionally, and an omitted optional one is materialized through
  `getDefaultValue()`. Anywhere else this is the same trap as the codegen
  defaults in milestone 2a, different file.
- **`wire()` is Reflection glue, so it gets a fixture matrix, not property
  tests.** Every branch is a shape a constructor can have, and each refusal
  happens before the constructor runs — a half-built subject would show the test
  a `TypeError` from inside code it did not write.
- **Fixtures are one class per file, or PSR-4 cannot find them.** Two of the
  wire fixtures were written into one file and every test that did not touch the
  other class failed with "there is no such class"; the same mistake reached
  review once already with `Fixture\Cls\Stamp`.

- **Forwarding is not gated on `$matched`, and strictness is.** Strict mode is a
  complaint that a matched expectation answers; forwarding is the mode's own
  answer, and an expectation that only counts the call —
  `expect(...)->times(1)` with no action — still has to get one. Gating it would
  make counting a call change what it returns.
- **Forwarding comes before the `never` fallback.** A `: never` method on a
  forwarding double has a real implementation to reach, and that implementation
  is where the throw lives.
- **A by-reference parameter is collected as `[&$slot]`, not `[$slot]`.** The
  copy would make `&` a promise the double cannot keep once the call is
  forwarded. Verified on 8.3 and 8.5: a reference survives both the array
  literal and the `...` spread; a copy does not.
- **`for($real)` remembers, `forwarding()` decides.** A double that started
  running real code the moment it was built would be a surprise rather than a
  shorthand, and `callOriginal()` needs the target either way.

- **A fixture's shape is the test input, so `tests/Fixture` is skipped by
  rector.** Rector once rewrote `Fixture\Cls\Ledger` into a promoted
  constructor property, which silently turned "this property has a declared
  default" into "this property has none" and made the property-initialization
  test assert the opposite of what it was written for. Improving a fixture is
  changing the contract under test.
- **A generated `__destruct()` must not declare a return type.** PHP rejects one
  with a fatal, not an exception, so it is rendered by hand rather than through
  the signature path. Every other magic method — including `__get`, `__set`,
  `__call`, `__invoke` and `__toString` — accepts the widened
  `OriginalType|ArgumentMatcher` parameter union on 8.3, 8.4 and 8.5.
- **Never call `getDefaultValue()` to find out what a default is.** On
  `= new Foo()` it runs the constructor. The default's source expression comes
  from `ReflectionParameter::__toString()`, which renders it fully qualified
  without reading the declaring file — and is the only way to see a `new`
  default without evaluating it. Two things it does not qualify:
  `self`/`static`/`parent`, which would resolve against the generated class, and
  a global constant, which Reflection reports prefixed with the declaring
  namespace and therefore under a name that does not exist.
- **A default that cannot be reproduced exactly refuses the target.** Answering
  with a different default than the contract's is a test that passes for the
  wrong reason.
- **An object default is not always at the front of the expression.**
  `[new Foo()]` and `['k' => new Foo()]` are legal initializers, and evaluating
  either runs a constructor. Detection scans the whole source with quoted runs
  blanked out, never just the first token.
- **`final` on a property is not `readonly`.** It stops a subclass from
  redeclaring the property; the outside can still write it, so the initializer
  fills it like any other.
- **A clone belongs to the context that cloned it.** `__clone()` runs on the
  copy and PHP hands it no reference to the original, so the original's owner
  cannot be recovered — this is a language limit, not a shortcut. Cloning inside
  a Fiber gives that Fiber the copy.
- **8.4-only syntax belongs in `eval`, never in a fixture file.** Property hooks,
  `final` properties and asymmetric visibility are a parse error on 8.3 — a file
  carrying them takes the whole suite down there rather than skipping a test.

- **`perf/` is a separate Composer project and must stay one.** Mockery,
  Prophecy and PHPUnit belong to the comparative benchmark harness, never to
  this package's `require-dev` — they would slow every `composer build` and
  mutation run for no gain, and the root `AGENTS.md` allows extra dev
  dependencies only for integration tests. It is `export-ignore`d, installed
  through a path repository, and run with `make perf` / `perf-cold` /
  `perf-memory`. Methodology and its traps live in `perf/README.md`; the rules
  there (teardown and verification inside the measured unit, no unbounded call
  history, scenarios defined by behaviour) are what make the numbers mean
  anything. Never edit a recorded figure without rerunning the harness that
  produced it.

- **State is keyed by the double object, never by `spl_object_id()`.** PHP
  reuses an object id after collection, so an id-keyed store would hand a
  fresh double the previous one's state. `Runtime` uses `WeakMap`s and
  `RuntimeContext` uses `SplObjectStorage`.
- **Recording is a depth counter, not a boolean.** A nested recording phase
  must not switch the enclosing one off when it unwinds.
- **An abstract static has nothing to inherit, so the double declares it.**
  Both shapes reach this: a class target's own `abstract public static`, and an
  interface static an abstract class implementing that interface never fills
  in — Reflection reports the latter with the *interface* as its declaring
  class. Treating either as inherited leaves the generated concrete subclass
  without an implementation, and PHP answers that with a fatal error naming the
  method, not an exception. `isOverridable()` is what routes them; only an
  implemented static on a class target belongs in `$classStatics`.
- **Multi-target methods are unified, not compared.** Parameters are
  contravariant, so `write(int)` and `write(string)` share the implementation
  `write(int|string)`; refusing them on signature difference would reject a
  doublable pair. Only genuinely unsatisfiable combinations are conflicts:
  divergent return types (covariant) and by-reference mismatches.
- **Generated methods collect arguments by name, never `func_get_args()`**,
  which omits parameters left at their default — `tag('alpha')` and
  `tag('alpha', 1)` must record as the same call.
- **`= null` on a non-nullable parameter is a deprecated implicit nullable.**
  When a parameter becomes optional through unification, `null` joins the type.
- **Mutation numbers lie unless every class has its own `#[Covers]` test.**
  Infection's mutant-to-test mapping is `#[Covers]`-driven: with one test class
  covering one class, the run reported 56 mutants at 93% MSI while the honest
  figures were 406 and 68%. Check the mutant count, not just the MSI.
- **Rector and Psalm pull in opposite directions here.** `RemoveUselessVarTag`
  and `FlipTypeControlToUseExclusiveType` are skipped in `rector.php` with the
  reasons written down; do not add skips beyond those without the same.
- Code: `declare(strict_types=1)`, `final readonly class` where nothing
  mutates, `#[\Override]`, explicit types, named arguments.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment. Never revert
  to floating `@vN` tags. Updates go through Dependabot. Workflows carry
  `permissions: { contents: read }` and `persist-credentials: false` on every
  checkout. Verify with `zizmor --persona=auditor .github/`.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit; and
  `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
