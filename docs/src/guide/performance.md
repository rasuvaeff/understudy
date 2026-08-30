---
title: Performance
description: "Comparative benchmarks against Mockery, Prophecy and PHPUnit — the methodology, the numbers, and what understudy does not win."
---

# Performance

Everything below is included verbatim from [`perf/README.md`](https://github.com/rasuvaeff/understudy/blob/master/perf/README.md)
in the repository, which is where the harness lives and where the numbers are
re-taken. Nothing is transcribed onto this page, so this page cannot disagree
with the harness that produced it.

Read the methodology before the tables. Two things in it decide how the numbers
should be read: teardown and verification are inside the measured unit, and the
loop count is part of the method rather than a knob.

<!--@include: ../../../perf/README.md#site-->
