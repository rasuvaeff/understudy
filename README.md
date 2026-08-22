# rasuvaeff/understudy

> **Work in progress — pre-release.** No stable API yet. The package will be
> published to Packagist with `v0.1.0`; until then everything here may change
> without notice.

Test double library for PHP 8.3+ with a call-closure API:

```php
when(fn () => $repo->find(123))->returns($book);
```

- **Call-closure API** — method and arguments are specified by a real call
  inside a closure, not by a string. Refactoring and IDE navigation work
  out of the box; a typo in a method name is impossible by construction.
- **Zero reserved methods** — a double carries no service methods
  (`expects`/`allows`/…); configuration lives in free functions.
- **Framework-agnostic core** — thin `-testo` / `-phpunit` adapters and a
  separate `-psalm` plugin follow, mirroring the
  [`rasuvaeff/property-testing-*`](https://packagist.org/packages/rasuvaeff/property-testing-core)
  split family.

## Current state: milestone 0 — feasibility spikes

`spikes/` contains executable feasibility fixtures for the mechanics the
design relies on. Each spike is a standalone script that exits non-zero on
the first broken promise. CI runs them on PHP 8.3, 8.4 and 8.5.

| Spike | Proves |
|---|---|
| `01-sentinel` | a sentinel exception escapes any native return type, including `never`; recording captures method + args |
| `02-matcher-union` | contravariant `T\|ArgumentMatcher` parameter widening compiles and runs; scalar coercion follows the calling file's `strict_types`; variadic and by-reference parameters accept matchers |
| `03-byref-return` | by-reference returns can be dispatched through a runtime with a stable external slot |
| `04-dnf-multitarget` | `(A&B)\|ArgumentMatcher` DNF parameters work; conflicting multi-target signatures are detected via Reflection before `eval` |
| `05-fiber-contexts` | per-Fiber runtime contexts don't leak between sibling fibers; a double owned by the main context records calls made inside a child fiber into the owner log |
| `06-bypass-finals` | a token-aware `file://` stream wrapper strips `final` from an allow-listed class only, preserving `__FILE__`, `__DIR__`, relative includes and sibling classes |
| `07-psalm-builder` | a Psalm `FunctionReturnTypeProviderInterface` hook can derive `WhenBuilder<TReturn>` from the closure body and flag a `returns()` type mismatch |

Run locally (no PHP on the host required):

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli bash spikes/run.sh
docker run --rm -v "$PWD":/app -w /app php:8.4-cli bash spikes/run.sh
docker run --rm -v "$PWD":/app -w /app php:8.5-cli bash spikes/run.sh
# Psalm spike:
docker run --rm -v "$PWD":/app -w /app composer:2 bash spikes/07-psalm-builder/run.sh
```

## License

BSD-3-Clause.
