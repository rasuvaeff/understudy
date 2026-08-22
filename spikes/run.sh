#!/usr/bin/env bash
# Runs feasibility spikes 01-06 with the PHP binary on PATH.
# Spike 07 (Psalm plugin) has its own runner: spikes/07-psalm-builder/run.sh.
set -u

cd "$(dirname "$0")"

php -v | head -1

status=0
for spike in 01-sentinel 02-matcher-union 03-byref-return 04-dnf-multitarget 05-fiber-contexts 06-bypass-finals; do
    if php "$spike/spike.php"; then
        echo "PASS $spike"
    else
        echo "FAIL $spike"
        status=1
    fi
done

exit $status
