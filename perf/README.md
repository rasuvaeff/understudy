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

## Results

Environment: PHP 8.5.6, Linux x86_64, `composer:2` image, no OPcache, no Xdebug,
container pinned to six cores with a raised CPU share on an otherwise idle
desktop. Understudy `dev-perf/comparative-benchmarks`, Mockery 1.6.15,
Prophecy 1.26.1, PHPUnit 12.5.33. Taken 2026-08-23; every table below was run
three times and the figures reproduce within a few percent.

Medians after outlier filtering, understudy first in each row:

### Building a double

| Contract | understudy | Mockery | Prophecy | PHPUnit `createStub` | PHPUnit `createMock` |
|---|---|---|---|---|---|
| 1 method | **2.08µs** | +355% | +1013% | +304% | +387% |
| 8 methods | **2.32µs** | +341% | +954% | +278% | +360% |

The cost does not move with the width of the contract — 2.08µs against 2.32µs —
because the generated class is compiled once and everything after that is
instantiation.

### A stub: build, stub, call, tear down

| | understudy | Mockery | Prophecy | PHPUnit `createStub` |
|---|---|---|---|---|
| 1 call | **14.7µs** | +45% | +125% | +27% |
| 20 calls | 48.0µs | +46% | +628%¹ | **−18%** |
| marginal cost of one call² | 1.75µs | 2.58µs | —¹ | **1.08µs** |

¹ Prophecy's twenty-call row carries ±85% relative deviation even after outlier
filtering — its per-call path allocates enough to make garbage collection, not
the call, the thing being measured. The number is not quotable and the marginal
cost cannot be derived from it.

² `(20 calls − 1 call) / 19`.

**PHPUnit dispatches a stubbed call faster than understudy** — 1.08µs against
1.75µs. Understudy is ahead on the whole one-call test only because building the
double costs it four times less; past roughly thirty calls to the same stub,
PHPUnit's test is the cheaper one.

### A mock: build, expect, call, verify, tear down

| | understudy | Mockery | Prophecy | PHPUnit `createMock` |
|---|---|---|---|---|
| plain expectation | **18.0µs** | +30% | +175% | +16% |
| with an argument matcher | **17.5µs** | +31% | +179% | —³ |

³ see "What is deliberately absent" above.

### Cold start

Medians of 25 processes, minus a baseline process that only loads the
autoloader (25.7ms):

| library | added to process start |
|---|---|
| understudy | **3.9ms** |
| Mockery | 7.3ms |
| Prophecy | 16.5ms |
| PHPUnit | 17.0ms |

### Memory

500 live doubles per contract, after one warmup double per library so that the
library's own autoloading is not billed to the first contract measured:

| library | first double of a contract | each further double |
|---|---|---|
| understudy | 3.5 KB (`Clock`), 76.1 KB (`Ledger`) | **339–354 B** |
| Mockery | 126.3 KB, 140.8 KB | 513 B |
| Prophecy | 13.5 KB, 35.5 KB | ~8.5 KB |
| PHPUnit | 9.5 KB, 70.6 KB | ~1.25 KB |

## The loop count is part of the method

The first version of these tables was wrong, and the way it was wrong is worth
keeping written down.

It timed two to three thousand doubles inside a single iteration, which put
PHPUnit at +7797% on creation and Prophecy at +4954%. Dropping to fifty doubles
per iteration moved them to +387% and +1013%. Nothing about the libraries
changed: the loop was building cyclic garbage faster than PHP's collector ran,
so most of what was being timed was collection, charged to whichever library
allocated most.

Fifty is the number a test plausibly builds, so fifty is what the benchmarks
use. The tell that this is happening is a raw `RStDev` in the hundreds of
percent next to a filtered one in single digits: a handful of iterations paid
for a collection and the rest did not.

Any change to `calls` invalidates every figure recorded here. Rerun, do not
scale.

## Keeping it honest over time

- Competitor versions are resolved, not pinned: `composer.lock` is not
  committed, and the resolved versions belong in the environment line above
  every set of numbers.
- Numbers go stale. Regenerate before a release and restate the environment;
  never edit a figure without rerunning the harness that produced it.
- The scenarios are behaviour contracts. Adding one is welcome; changing what an
  existing one measures invalidates every number recorded against it.
