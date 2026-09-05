# Examples

Every script is runnable as-is and needs nothing but the package itself.

Each one checks itself: a mismatch throws and the script exits non-zero, so
`bin/package-audit understudy` runs them as a gate rather than linting them.
That makes these files executable documentation — they break when the API they
demonstrate changes.

| Script | Shows | Needs server? |
|---|---|---|
| `basic-usage.php` | stubbing with `when()`, argument matchers — `Arg::rest()` and a typed `Arg::captor()` included — verifying counts, reading the call log with outcomes, strict mode and labels | no |
| `property-hooks.php` | doubling a contract that declares properties (PHP 8.4+): default reads, `{ get; set; }` round-trip, the get-only write refusal — self-skipping on 8.3 | no |
| `modes.php` | the three modes a double can be in: loose defaults, `strict()`, and `forwarding()` to a real object — including the partial double (`delegate()` + a stub on top) and `lean()`, the call log that does not retain returned values | no |
| `wiring.php` | `Understudy::wire()`: doubles keyed by constructor parameter name, overriding one dependency, and the refusal that happens before the constructor runs | no |
| `protocol.php` | `expect()->ordered()`, `Understudy::verifySequence()` across two doubles, and `Understudy::nothingElse()` | no |
| `defaults-registry.php` | what an unconfigured call answers: nested doubles one level deep, `Understudy::defaults()`, the nullable-return rule, and the refusals | no |
| `structured-failures.php` | reading `VerificationFailed::failures()` as data — the path an adapter or reporter takes instead of parsing the message | no |

`_check.php` is the shared assertion helper the scripts include; the leading
underscore is what marks it an include rather than a script of its own.

`case-studies/` holds the cookbook scenarios: each one reproduces a real
failure message quoted on a [cookbook](../docs/src/cookbook) page of the
documentation site. They are gated by `make docs-cookbook`, which diffs their
output against the pages — not by `composer build` — and their `_bootstrap.php`
is the include, same convention as `_check.php`.

There is deliberately no `bypassFinals()` example. It depends on load order and
on the source arriving through `file://`, so a script cannot assert its own
outcome everywhere; those claims live in the acceptance scenarios
(`tests/Integration/BypassFinalsIntegrationTest.php`), which run a process per
claim.

Run one with the same Docker image the build uses:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/modes.php
```

Or, if you have PHP on the host:

```bash
php examples/modes.php
```
