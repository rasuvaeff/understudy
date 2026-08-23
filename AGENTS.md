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
