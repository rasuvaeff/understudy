# Comparative benchmarks

Understudy against the three established PHP test-double libraries: Mockery,
Prophecy and PHPUnit's own `MockObject`.

This is a separate Composer project on purpose. The libraries it measures are
heavy, they would slow every `composer build` and mutation run in the package,
and the root `AGENTS.md` allows extra dev dependencies only for integration
tests. Nothing here is part of `rasuvaeff/understudy`; the package is installed
through a path repository.

## Running it

```bash
make perf-install     # once, and after changing a competitor constraint
make perf             # the in-process benchmarks
make perf-cold        # cold start: one process per double
make perf-memory      # bytes retained per live double
```

Or directly, from `perf/`:

```bash
composer update
vendor/bin/testo --suite=Benchmarks -vv
php cold-start.php 25
php memory.php 500
```

## What is measured, and why that way

| Harness | Unit | Why it exists |
|---|---|---|
| `CreateBench` | build a double, tear the scope down | the cost every test pays per double |
| `StubBench` | build, stub, call *n* times, tear down | `(20 calls − 1 call) / 19` is the marginal cost of a call |
| `MockBench` | build, expect, call, verify, tear down | verification is inside the unit, where a test puts it |
| `cold-start.php` | a whole PHP process | code generation, paid once per worker process |
| `memory.php` | bytes held by live doubles | what a test with many doubles keeps in memory |

Four rules keep the comparison honest, and each one cost something to obey:

**Teardown is inside the measured unit.** Understudy and Mockery keep their
bookkeeping in process-global state that a test has to clear (`reset()`,
`Mockery::close()`); Prophecy hangs it on a `Prophet` and PHPUnit on the
`TestCase`, so those are constructed per iteration. Leaving teardown out would
have measured two libraries mid-leak and the other two clean.

**Verification is inside the measured unit.** A library that defers its
bookkeeping to `verify()` looks fast right up until something calls it, and
every test does.

**No unbounded call history.** A benchmark that builds one double and calls it
fifty thousand times measures an invocation log nobody accumulates — and all
four libraries grow one. Per-call cost is read as the slope between a
one-call and a twenty-call test instead.

**Scenarios are defined by behaviour, not by API name.** A "stub" in Prophecy,
`createStub()` in PHPUnit and `shouldReceive()` in Mockery are not the same
concept. What is held equal is the observable outcome: a call returned a canned
value, an expectation was checked, an argument was matched.

### What is deliberately absent

- **PHPUnit in the argument-matcher table.** `->with(...)` verification runs
  through `MockObject\Rule\Parameters::doVerify()`, which increments the
  assertion count of the test case it finds by walking the call stack. Outside a
  PHPUnit process there is none and it throws
  `NoTestCaseObjectOnCallStackException`. That path cannot be measured from this
  harness at all; it would need a PHPUnit-native one.
- **Class doubles.** Understudy doubles interfaces only until milestone 2. Every
  contract here is an interface, which all four libraries support natively.
- **A pass/fail gate.** These numbers are informational. They do not gate a
  build and must not: the variance below would make that a coin toss.

## Reading the numbers

Compare **within** a table, never across them. Each table is one benchmark run
with its own warmup, and PHP's state when a table starts depends on every table
before it.

Watch the `RStDev` column. Testo calls a result stable below 2%; anything in
double digits means the machine was busy and the ratio, not the microsecond
figure, is the only thing worth quoting.

## Indicative results

**These are not publication-grade numbers.** They were taken on a developer
machine with other containers running, and several rows carry a `Very high
variance` danger. They are recorded here to show the shape of the result and to
make regressions visible; final figures need a quiet machine.

Environment: PHP 8.5.6, Linux x86_64, `composer:2` image, no OPcache, no Xdebug.
Understudy `dev-perf/comparative-benchmarks`, Mockery 1.6.15, Prophecy 1.26.1,
PHPUnit 12.5.33. Taken 2026-08-23.

Medians, understudy = baseline:

| Scenario | understudy | Mockery | Prophecy | PHPUnit |
|---|---|---|---|---|
| create + teardown, 1-method contract | 2.23µs | +533% | +4465% | +6002% |
| create + teardown, 8-method contract | 2.26µs | +521% | +5519% | +8530% |
| stub + 1 call | 20.30µs | +106% | +134% | +237% |
| stub + 20 calls | 68.60µs | +141% | +82% | +286% |
| marginal cost of one call¹ | 2.54µs | 6.50µs | 4.08µs | 10.35µs |
| expect + call + verify | 24.00µs | +209% | +206% | +756% |
| expect + matcher + call + verify | 10.09µs | +48% | +206% | — |

¹ derived as `(20 calls − 1 call) / 19` from the two rows above it.

Cold start, medians of 15 processes, minus a baseline process that only loads
the autoloader (38.8ms):

| library | added to process start |
|---|---|
| understudy | 3.2ms |
| Mockery | 7.4ms |
| Prophecy | 23.5ms |
| PHPUnit | 24.1ms |

Memory, 500 live doubles per contract, after one warmup double per library so
that the library's own autoloading is not billed to the first contract measured:

| library | first double of a contract | each further double |
|---|---|---|
| understudy | 3.5 KB (`Clock`), 76.1 KB (`Ledger`) | 339–354 B |
| Mockery | 126.3 KB, 140.8 KB | 513 B |
| Prophecy | 13.5 KB, 35.5 KB | ~8.5 KB |
| PHPUnit | 9.5 KB, 70.6 KB | ~1.25 KB |

## Keeping it honest over time

- Competitor versions are resolved, not pinned: `composer.lock` is not
  committed, and the resolved versions belong in the environment line above
  every set of numbers.
- Numbers go stale. Regenerate before a release and restate the environment;
  never edit a figure without rerunning the harness that produced it.
- The scenarios are behaviour contracts. Adding one is welcome; changing what an
  existing one measures invalidates every number recorded against it.
