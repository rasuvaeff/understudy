DOCKER := docker run --rm -v "$(PWD)":/app -w /app composer:2
DOCKER_HOST := docker run --rm --network host -v "$(PWD)":/app -w /app

# `composer:2` ships no coverage driver, so anything measuring coverage needs
# one installed. Installing it per run — apk add $$PHPIZE_DEPS, then pecl
# install pcov, compiled from source — cost three to five minutes before the
# first mutant, every time, and was also the most fragile step in the file: a
# transient apk extraction error took the whole target down without a single
# test having run. The full mutation run itself is about a minute, so the
# bootstrap was the cost.
#
# Nothing in the image is package-specific, so every package in the monorepo
# shares this one tag. It pins the PHP that coverage runs use — after a
# `composer:2` bump, `make pcov-image-refresh`.
PCOV_IMAGE := composer-pcov:local
DOCKER_PCOV := docker run --rm -v "$(PWD)":/app -w /app --entrypoint sh $(PCOV_IMAGE) -lc

# What `mutation-diff` measures against. A branch is compared to the trunk it
# will merge into; override for a stacked branch.
MUTATION_BASE ?= origin/master

# What `docs-vale` lints: the hand-written pages only. docs/src/api/** is
# generated from docblocks, and a prose linter there produces findings whose
# only fix is rewriting PHP to satisfy a style rule.
VALE_PATHS ?= docs/src/index.md docs/src/guide docs/src/cookbook docs/src/adapters

.PHONY: bench build cs cs-fix psalm test mutation mutation-diff rector rector-fix install normalize \
       require-checker test-coverage test-coverage-ci update-deps release-check bc-check audit-package \
       help perf perf-install perf-cold perf-memory pcov-image pcov-image-refresh \
       docs-install docs-api docs-dev docs-build docs-cookbook docs-migration docs-links docs-vale

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

# Built once and then reused; the recipe is a no-op when the image is there.
pcov-image:
	@docker image inspect $(PCOV_IMAGE) >/dev/null 2>&1 || $(MAKE) pcov-image-refresh

pcov-image-refresh:
	@echo "Building $(PCOV_IMAGE) from composer:2 ..."
	@printf 'FROM composer:2\nRUN apk add --no-cache $$PHPIZE_DEPS >/dev/null \\\n && pecl install pcov >/dev/null \\\n && docker-php-ext-enable pcov\n' \
	  | docker build -t $(PCOV_IMAGE) - >/dev/null

test-coverage: pcov-image
	$(DOCKER_PCOV) 'composer test:coverage'

test-coverage-ci: pcov-image
	$(DOCKER_PCOV) 'composer test:coverage:ci'

mutation: pcov-image
	$(DOCKER_PCOV) 'composer mutation'

# Only the mutants the branch moved. The gate is still the full run — this is
# the loop to iterate in while a change is still being written, and it answers
# in seconds where the whole suite answers in about a minute.
#
# git needs safe.directory: the repository is bind-mounted and owned by another
# uid inside the container, and Infection shells out to git to resolve the diff.
mutation-diff: pcov-image
	$(DOCKER_PCOV) 'git config --global --add safe.directory /app; \
	  composer mutation -- --git-diff-lines --git-diff-base=$(MUTATION_BASE)'

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

# --- Documentation site (docs/, plan §9) ---------------------------------
#
# Node runs on the host: the site build needs no PHP at all, and the
# `composer:2` image carries no Node. The PHP-side targets that will join
# these — docs-api, docs-rules, docs-cookbook — go through $(DOCKER) with a
# PHP_BIN prefix, because they do need an interpreter.

docs-install:
	cd docs && npm ci

# The two reflectors need PHP and the API workspace; everything after them is
# Node only, which is what keeps `docs-build` runnable on a machine with no PHP.
docs-api:
	$(DOCKER) sh -c 'git config --global --add safe.directory "*"; cd docs/.api-workspace && composer install --no-interaction --no-progress -q'
	$(DOCKER) sh -c 'git config --global --add safe.directory "*"; DOCS_UNDERSTUDY_VERSION=$$(git describe --tags --always 2>/dev/null) php docs/scripts/reflect-api.php' > docs/scripts/api-snapshot.json
	$(DOCKER) php docs/scripts/reflect-rules.php > docs/scripts/rules-snapshot.json
	cd docs && npm run docs:api

docs-dev:
	cd docs && npm run docs:dev

docs-build:
	cd docs && npm run docs:build

docs-cookbook:
	PHP_BIN="$(DOCKER) php" node docs/scripts/check-cookbook.mjs

docs-migration:
	cd docs && npm run docs:migration

docs-links:
	cd docs && npm run docs:check:links

docs-vale:
	vale sync
	vale $(VALE_PATHS)

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
	@echo "  mutation-diff    mutate only what this branch changed"
	@echo "  pcov-image       build the coverage image if it is missing"
	@echo "  rector           check rector (dry-run)"
	@echo "  rector-fix       apply rector fixes"
	@echo "  normalize        normalize composer.json"
	@echo "  require-checker  check composer dependencies"
	@echo "  update-deps      composer update + normalize"
	@echo "  bc-check         check backward compatibility against latest tag"
	@echo "  release-check    build + rector + bc-check + mutation"
	@echo ""
	@echo "  docs-install     install the documentation site dependencies"
	@echo "  docs-api         re-reflect the API and rules snapshots, then render the pages"
	@echo "  docs-dev         serve the documentation site locally"
	@echo "  docs-build       build the documentation site"
	@echo "  docs-cookbook    verify the cookbook case studies reproduce their output"
	@echo "  docs-migration   re-render MIGRATION.md from the migrating-* pages"
	@echo "  docs-links       check external links"
	@echo "  docs-vale        lint the hand-written prose"

audit-package:
	@if [ -f ../bin/package-audit ]; then bash ../bin/package-audit "$(CURDIR)"; else echo "package-audit: available only inside the monorepo"; fi
