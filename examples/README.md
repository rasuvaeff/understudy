# Examples

Every script is runnable as-is and needs nothing but the package itself.

| Script | Shows | Needs server? |
|---|---|---|
| [basic-usage.php](basic-usage.php) | stubbing with `when()`, argument matchers, verifying counts, reading the call log with outcomes, strict mode and labels | no |

Run one with the same Docker image the build uses:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic-usage.php
```

Or, if you have PHP on the host:

```bash
php examples/basic-usage.php
```
