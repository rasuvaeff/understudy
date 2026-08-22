#!/usr/bin/env bash
# Spike 07: proves a Psalm FunctionReturnTypeProvider can derive
# WhenBuilder<TReturn> from the closure body via public plugin hooks.
#
# Expected: fixtures/positive.php analyzes clean (including an exact
# check-type on the inferred generic), fixtures/negative.php reports
# exactly one InvalidArgument on the returns('oops') call.
set -u

cd "$(dirname "$0")"

if [ ! -d vendor ]; then
    composer install --no-interaction --quiet || exit 1
fi

status=0

echo "07-psalm-builder"

positive=$(vendor/bin/psalm --no-cache --output-format=json -- fixtures/positive.php 2>/dev/null)
if [ "$(printf '%s' "$positive" | php -r 'echo count(json_decode(stream_get_contents(STDIN), true));')" = "0" ]; then
    echo "  ok: positive fixture is clean; exact generic WhenBuilder<Book|null> confirmed"
else
    echo "  FAIL: positive fixture reported issues:"
    printf '%s\n' "$positive"
    status=1
fi

negative=$(vendor/bin/psalm --no-cache --output-format=json -- fixtures/negative.php 2>/dev/null)
summary=$(printf '%s' "$negative" | php -r '
    $issues = json_decode(stream_get_contents(STDIN), true);
    $lines = [];
    foreach ($issues as $issue) {
        $lines[] = $issue["type"] . ":" . basename($issue["file_name"]);
    }
    echo implode(",", $lines);
')
if [ "$summary" = "InvalidArgument:negative.php" ]; then
    echo "  ok: negative fixture reports exactly one InvalidArgument for returns('oops')"
else
    echo "  FAIL: negative fixture summary: ${summary:-<empty>}"
    printf '%s\n' "$negative"
    status=1
fi

if [ $status -eq 0 ]; then
    echo "PASS 07-psalm-builder"
else
    echo "FAIL 07-psalm-builder"
fi

exit $status
