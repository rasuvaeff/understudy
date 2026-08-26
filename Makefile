DOCKER := docker run --rm -v "$(PWD)":/app -w /app composer:2
DOCKER_HOST := docker run --rm --network host -v "$(PWD)":/app -w /app
PCOV_BOOTSTRAP := apk add --no-cache $$PHPIZE_DEPS >/dev/null && pecl install pcov >/dev/null && docker-php-ext-enable pcov

.PHONY: bench build cs cs-fix psalm test mutation rector rector-fix install normalize require-checker \
       test-coverage test-coverage-ci update-deps release-check bc-check audit-package help \
       perf perf-install perf-cold perf-memory

install:
	$(DOCKER) composer install --no-interaction --no-progress --prefer-dist

bench:
	$(DOCKER) composer bench

# The comparative harness is a separate Composer project (see perf/README.md),
# so it needs the repository root mounted, not just the package directory: its
# path repository resolves `..`.
# Pinned and prioritised because the README quotes the environment its
# numbers were taken in. Leaving that in a shell history makes the
# figures unreproducible by anyone who was not there.
PERF := docker run --rm --cpuset-cpus=0-5 --cpu-shares=2048 -v "$(PWD)":/repo -w /repo/perf composer:2

perf-install:
	$(PERF) sh -c 'git config --global --add safe.directory "*"; composer update --no-interaction --no-progress'

perf:
	$(PERF) vendor/bin/testo --suite=Benchmarks -vv

perf-cold:
	$(PERF) php cold-start.php 25

perf-memory:
	$(PERF) php memory.php 500

build:
	$(DOCKER) composer build

cs:
	$(DOCKER) composer cs

cs-fix:
	$(DOCKER) composer cs:fix

psalm:
	$(DOCKER) composer psalm

test:
	$(DOCKER) composer test

test-integration:
	$(DOCKER) composer test:integration

examples:
	$(DOCKER) composer examples

test-coverage:
	$(DOCKER) sh -lc '$(PCOV_BOOTSTRAP) && composer test:coverage'

test-coverage-ci:
	$(DOCKER) sh -lc '$(PCOV_BOOTSTRAP) && composer test:coverage:ci'

mutation:
	$(DOCKER) sh -lc '$(PCOV_BOOTSTRAP) && composer mutation'

rector:
	$(DOCKER) composer rector

rector-fix:
	$(DOCKER) composer rector:fix

normalize:
	$(DOCKER) sh -c 'git config --global --add safe.directory /app; composer normalize'

require-checker:
	$(DOCKER) composer require-checker

update-deps:
	$(DOCKER) sh -c 'git config --global --add safe.directory /app; composer update -q; composer normalize'

# composer's release-check chain ends in bc-check, which shells out to git —
# without safe.directory the container's git refuses the bind-mounted repo
# ("dubious ownership") and the whole target dies with exit 128.
#
# The wildcard is deliberate here, and narrowing it to /app is a silent
# regression: roave clones the repository into a temporary directory of its
# own, which /app does not cover. git then refuses that clone, `git describe`
# is swallowed by 2>/dev/null, and the script reports "No previous tag" and
# exits zero — a green release-check that never ran a compatibility check at
# all. That is how a major shipped unverified from yii3-webhooks-db on
# 2026-08-22. Verify by the "Detected last version: vX.Y.Z" line in the
# output, never by the exit code.
release-check:
	$(DOCKER) sh -c 'git config --global --add safe.directory "*"; composer release-check'
	$(MAKE) mutation

# Wildcard for the same reason as release-check above: roave's temporary clone
# lives outside /app.
bc-check:
	$(DOCKER) sh -c 'git config --global --add safe.directory "*"; \
	  LATEST=$$(git describe --tags --abbrev=0 2>/dev/null || true); \
	  if [ -n "$$LATEST" ]; then \
	    composer bc-check -- --from=$$LATEST; \
	  else \
	    echo "No previous tag - skipping BC check"; \
	  fi'

help:
	@echo "Usage: make <target>"
	@echo ""
	@echo "Targets:"
	@echo "  install          composer install"
	@echo "  bench            run benchmarks (Benchmarks suite)"
	@echo "  perf-install     install the comparative benchmark harness"
	@echo "  perf             benchmark against Mockery/Prophecy/PHPUnit"
	@echo "  perf-cold        cold-start comparison (one process per double)"
	@echo "  perf-memory      bytes retained per live double"
	@echo "  build            full gate (validate + normalize + cs + psalm + test + integration + examples)"
	@echo "  cs               check code style (dry-run)"
	@echo "  cs-fix           fix code style"
	@echo "  psalm            static analysis"
	@echo "  test             run testo (Unit suite)"
	@echo "  test-integration run testo (Integration suite)"
	@echo "  examples         run every example script (they check themselves)"
	@echo "  test-coverage    run testo with coverage"
	@echo "  test-coverage-ci run testo coverage for CI artifacts"
	@echo "  mutation         mutation testing"
	@echo "  rector           check rector (dry-run)"
	@echo "  rector-fix       apply rector fixes"
	@echo "  normalize        normalize composer.json"
	@echo "  require-checker  check composer dependencies"
	@echo "  update-deps      composer update + normalize"
	@echo "  bc-check         check backward compatibility against latest tag"
	@echo "  release-check    build + rector + bc-check + mutation"

audit-package:
	@if [ -f ../bin/package-audit ]; then bash ../bin/package-audit "$(CURDIR)"; else echo "package-audit: available only inside the monorepo"; fi
