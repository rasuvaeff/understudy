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
variable in the root `Makefile`, not by flags typed at a prompt. Understudy at
`442da10`, Mockery 1.6.15, Prophecy 1.26.1, PHPUnit 12.5.33. Taken 2026-08-27,
before the 0.2.0 tag.

**The previous set was taken at `9837dfd` — a commit before 0.1.0**, so it
described no released version at all. Between it and the first tag a regression
landed that those figures did not show: double creation went from about 1.7µs to
about 2.0µs in the same harness, and it shipped in 0.1.0, 0.1.1 and 0.1.2. It was
bisected to #23 and is still present. Removing it was attempted and reverted —
see the invariant in the package's `AGENTS.md`: the per-double loop in
`Runtime::retire()` looks removable and is not, and replacing it moved the cost
onto creation instead, 13-23% worse in this harness.

**Decision (closes #56): the cost is accepted.** #23 is the core review round
that made registration, reset, `idle()` and aggregate verification bounded and
predictable, and closed the Fiber accounting hole — a test body run in a Fiber
could leave an unmet `expect()` and the suite stayed green. That is a false
*pass*, the one failure mode a verification library must not have, and it is
worth ~0.3µs on every double built. The figures in this file describe the
library as shipped, with that trade in it. Reopening the trade takes what the
attempt above took: a change that removes the cost without reopening the hole,
judged by an A/B of the full harness on a quiet machine, three runs a side,
with the competitors' movement as the noise floor — never by an isolated
micro-benchmark, which cannot see a cost that was moved.

Every in-process table below was run three times; medians move by 0.2-3.5%
between runs. Figures are **filtered means** — testo's `Mean*`, after outlier
rejection — and the relative deviation of that filtered set is under 5%
everywhere quoted except the one-call stub row, where it reaches 7%: that
scenario is short enough that process noise still shows through, and its
medians are quoted because they reproduce to 0.2% across the three runs even
though each run is internally noisier.

**These runs were taken on an idle machine, and the difference matters.** The
same harness, on a machine also running Docker builds, read double creation 35%
higher and invented a gap between the one-method and eight-method contracts that
does not exist. An A/B against another commit, three runs a side, with the other
libraries' movement in the same runs as the noise floor, is the only comparison
worth acting on.

### Building a double

| Contract | understudy | Mockery | Prophecy | PHPUnit `createStub` | PHPUnit `createMock` |
|---|---|---|---|---|---|
| 1 method | **2.06µs** | +216% | +683% | +155% | +159% |
| 8 methods | **2.06µs** | +217% | +641% | +158% | +159% |

The cost still does not move with the width of the contract — 2.06µs either way
— because the generated class is compiled once and everything after that is
instantiation. A busy machine will suggest otherwise; it is wrong.

Against the previous figures this row is 60% worse, and that is the #23
regression described above, not a change since 0.1.2.

### A stub: build, stub, call, tear down

| | understudy | Mockery | Prophecy | PHPUnit `createStub` |
|---|---|---|---|---|
| 1 call | 10.6µs | +17% | +76% | **−17%** |
| 20 calls | 27.1µs | +59% | +75% | **−19%** |
| marginal cost of one call² | 0.86µs | 1.61µs | 1.51µs | **0.69µs** |

¹ Prophecy's twenty-call row was unquotable in the previous set — ±72% after
filtering. On an idle machine it settles to ±3.7% and is quoted here.

² `(20 calls − 1 call) / 19`.

**PHPUnit is ahead of understudy on the whole stub scenario, at both ends.** It
builds the double more slowly and dispatches more cheaply, and in this
environment the second effect wins from the first call onward: 8.77µs against
10.6µs at one call, 0.69µs against 0.86µs per call after. There is no crossover
at which understudy's stub test becomes the cheaper one.

That is a change of conclusion, not only of numbers. The previous figures had
understudy ahead by 27% on the one-call test and PHPUnit overtaking it at
roughly thirty calls. What moved understudy''s side was the dispatch work in
#16 — per-call cost went from 1.75µs to 0.82µs, closing the ratio against
PHPUnit from 1.62× to 1.15× — and it moved the build cost too, from 2.08µs to
1.28µs. PHPUnit''s own figures improved by more.

### A mock: build, expect, call, verify, tear down

| | understudy | Mockery | Prophecy | PHPUnit `createMock` |
|---|---|---|---|---|
| plain expectation | 12.8µs | +4% | +128% | **−27%** |
| with an argument matcher | **12.2µs** | +5% | +115% | —³ |

³ see "What is deliberately absent" above.

An argument matcher costs understudy nothing measurable over a plain
expectation — 10.77µs either way.

### Cold start

Medians of 25 processes, minus a baseline process that only loads the
autoloader. **Quote the ratio, not the milliseconds:** the absolutes move far
more between runs than testo calls stable, and the ratios hold much better.

| library | added to process start | ×understudy |
|---|---|---|
| understudy | 1.59ms | **1.00×** |
| Mockery | 2.39ms | 1.50× |
| Prophecy | 7.91ms | 4.96× |
| PHPUnit | 8.58ms | 5.38× |

Mockery's ratio has been unstable across sets — 1.87×, then 1.30–1.53×, now
1.50× — across commits that cannot touch Mockery. Recorded as observed, without
a cause.

### Memory

500 live doubles per contract, after one warmup double per library so that the
library''s own autoloading is not billed to the first contract measured.
Deterministic: two runs, identical to the byte.

| library | first double of a contract | each further double |
|---|---|---|
| understudy | 4.5 KB (`Clock`), 13.6 KB (`Ledger`) | **467–482 B** |
| Mockery | 126.3 KB, 140.8 KB | 513 B |
| Prophecy | 13.5 KB, 35.5 KB | ~8.5 KB |
| PHPUnit | 9.5 KB, 134.6 KB | ~1.25 KB |

**Per-double memory has grown, and it is ours.** The harness was re-run at the
commit the previous figures were taken at and reproduced them exactly — 354 B
and 339 B, Mockery''s `Clock` at 126.3 KB — so the environment is comparable and
the movement is code:

| state | `Clock` | `Ledger` |
|---|---|---|
| #8, where the old figures were taken | 354 B | 339 B |
| after #9–#14 (class doubles, forwarding, defaults, by-ref, bypass) | 418 B | 403 B |
| after #16 (dispatch) | 450 B | 435 B |
| at `442da10`, before 0.2.0 | 482 B | 467 B |

The last row is another 32 bytes, over the same range that carries the #23
creation regression. It has not been attributed to a single change.

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
