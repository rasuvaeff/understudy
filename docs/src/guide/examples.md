---
title: Examples
description: "The runnable scripts in examples/ — each one checks itself, so they break when the API they demonstrate changes."
---

# Examples

Runnable scripts live in [`examples/`](https://github.com/rasuvaeff/understudy/tree/master/examples).
Every one is runnable as-is and needs nothing but the package itself.

Each script **checks itself**: a mismatch throws and the script exits non-zero,
so `bin/package-audit` runs them as a gate rather than linting them. That makes
them executable documentation — they break when the API they demonstrate
changes.

| Script | Shows |
|---|---|
| `basic-usage.php` | stubbing with `when()`, argument matchers — `Arg::rest()` and a typed `Arg::captor()` included — verifying counts, reading the call log with outcomes, strict mode and labels |
| `property-hooks.php` | doubling a contract that declares properties (PHP 8.4+): default reads, `{ get; set; }` round-trip, the get-only write refusal — self-skipping on 8.3 |
| `modes.php` | the three modes a double can be in: loose defaults, `strict()`, and `forwarding()` to a real object — including the partial double (`delegate()` plus a stub on top) and `lean()` |
| `wiring.php` | `Understudy::wire()`: doubles keyed by constructor parameter name, overriding one dependency, and the refusal that happens before the constructor runs |
| `protocol.php` | `expect()->ordered()`, `Understudy::verifySequence()` across two doubles, and `Understudy::nothingElse()` |
| `defaults-registry.php` | what an unconfigured call answers: nested doubles one level deep, `Understudy::defaults()`, the nullable-return rule, and the refusals |
| `structured-failures.php` | reading `VerificationFailed::failures()` as data — the path an adapter or reporter takes instead of parsing the message |

`_check.php` is the shared assertion helper the scripts include; the leading
underscore marks it an include rather than a script of its own.

## No `bypassFinals()` example, deliberately

It depends on load order and on the source arriving through `file://`, so a
script cannot assert its own outcome everywhere. Those claims live in the
acceptance scenarios (`tests/Integration/BypassFinalsIntegrationTest.php`),
which run a process per claim.

## The Cookbook is the other half

`examples/` demonstrates the API one concept at a time. The
[Cookbook](/cookbook/index) starts from a real incident and shows what went
wrong first — the scripts behind those pages live in
`examples/case-studies/`, and this site checks their output against what the
pages quote.
