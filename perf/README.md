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

Environment: PHP 8.5.6, Linux x86_64, `composer:2` image, no OPcache, no Xdebug.
The container is pinned to six cores with a raised CPU share — by the `PERF`
variable in the root `Makefile`, not by flags typed at a prompt, which is what
the previous set of figures depended on and why they are replaced here rather
than compared against. Understudy at `9837dfd`, Mockery 1.6.15, Prophecy
1.26.1, PHPUnit 12.5.33. Taken 2026-08-23, after the dispatch work in #16.

Every in-process table below was run three times; all rows reproduce within 3%
except Prophecy's twenty-call one, which is noted where it appears. Cold start
was run three times too and does not reproduce that tightly — see its own
section. Memory is deterministic: two runs, byte-identical.

Figures are **filtered means** — testo's `Mean*`, after outlier rejection —
with the relative deviation of that filtered set under 5% everywhere quoted.

### Building a double

| Contract | understudy | Mockery | Prophecy | PHPUnit `createStub` | PHPUnit `createMock` |
|---|---|---|---|---|---|
| 1 method | **1.28µs** | +359% | +936% | +255% | +302% |
| 8 methods | **1.29µs** | +354% | +919% | +254% | +298% |

The cost still does not move with the width of the contract — 1.28µs against
1.29µs — because the generated class is compiled once and everything after that
is instantiation.

### A stub: build, stub, call, tear down

| | understudy | Mockery | Prophecy | PHPUnit `createStub` |
|---|---|---|---|---|
| 1 call | 8.74µs | +30% | +81% | **−7%** |
| 20 calls | 24.3µs | +75% | —¹ | **−11%** |
| marginal cost of one call² | 0.82µs | 1.64µs | —¹ | **0.71µs** |

¹ Prophecy's twenty-call row still carries ±72% relative deviation after outlier
filtering, and moves 13.9% between runs — its per-call path allocates enough to
make garbage collection, not the call, the thing being measured. Neither the
number nor a marginal cost derived from it is quotable.

² `(20 calls − 1 call) / 19`.

**PHPUnit is ahead of understudy on the whole stub scenario, at both ends.** It
builds the double more slowly and dispatches more cheaply, and in this
environment the second effect wins from the first call onward: 8.14µs against
8.74µs at one call, 0.71µs against 0.82µs per call after. There is no crossover
at which understudy''s stub test becomes the cheaper one.

That is a change of conclusion, not only of numbers. The previous figures had
understudy ahead by 27% on the one-call test and PHPUnit overtaking it at
roughly thirty calls. What moved understudy''s side was the dispatch work in
#16 — per-call cost went from 1.75µs to 0.82µs, closing the ratio against
PHPUnit from 1.62× to 1.15× — and it moved the build cost too, from 2.08µs to
1.28µs. PHPUnit''s own figures improved by more.

### A mock: build, expect, call, verify, tear down

| | understudy | Mockery | Prophecy | PHPUnit `createMock` |
|---|---|---|---|---|
| plain expectation | 10.8µs | +19% | +159% | **−10%** |
| with an argument matcher | **10.8µs** | +20% | +148% | —³ |

³ see "What is deliberately absent" above.

An argument matcher costs understudy nothing measurable over a plain
expectation — 10.77µs either way.

### Cold start

Medians of 25 processes, minus a baseline process that only loads the
autoloader. **Quote the ratio, not the milliseconds:** understudy''s added time
spans 1.95–2.52ms across three runs, a 29% spread, far outside the 2% testo
calls stable. The ratios hold much better than the absolutes do.

| library | added to process start | ×understudy |
|---|---|---|
| understudy | 1.95–2.52ms | **1.00×** |
| Mockery | 2.97–3.49ms | 1.30–1.53× |
| Prophecy | 8.50–9.12ms | 3.62–4.43× |
| PHPUnit | 8.84–10.87ms | 3.86–4.94× |

Mockery''s ratio moved — it was 1.87× when these were last taken, and nothing in
Mockery changed. Recorded as observed, without a cause.

### Memory

500 live doubles per contract, after one warmup double per library so that the
library''s own autoloading is not billed to the first contract measured.
Deterministic: two runs, identical to the byte.

| library | first double of a contract | each further double |
|---|---|---|
| understudy | 4.0 KB (`Clock`), 13.1 KB (`Ledger`) | **435–450 B** |
| Mockery | 190.3 KB, 140.8 KB | 513 B |
| Prophecy | 13.5 KB, 35.5 KB | ~8.5 KB |
| PHPUnit | 9.5 KB, 70.6 KB | ~1.25 KB |

**Per-double memory has grown, and it is ours.** The harness was re-run at the
commit the previous figures were taken at and reproduced them exactly — 354 B
and 339 B, Mockery''s `Clock` at 126.3 KB — so the environment is comparable and
the movement is code:

| state | `Clock` | `Ledger` |
|---|---|---|
| #8, where the old figures were taken | 354 B | 339 B |
| after #9–#14 (class doubles, forwarding, defaults, by-ref, bypass) | 418 B | 403 B |
| after #16 (dispatch) | 450 B | 435 B |

The last step is the price of the dispatch work: #16 keeps a second,
reverse-ordered list of expectations so that matching does not rebuild one per
call, and that list costs about 32 bytes per live double. Against 0.93µs off
every call, on a test holding a few hundred doubles, that is a trade worth
making — but it is a trade, and it belongs in the table rather than in a commit
message.

`Ledger`''s *first* double went the other way, 76.1 KB to 13.1 KB, over the same
range. Mockery''s `Clock` first-double moved 126.3 KB to 190.3 KB across commits
that cannot touch Mockery; like its cold-start ratio, that is recorded as
observed rather than explained.

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
