# Slate — developer entry points.
# Dependency-free by design: plain PHP, no composer, no PHPUnit, so every
# target runs on shared hosting exactly as production does.

PHP ?= php
FIND_PHP = find . -name '*.php' -not -path './vendor/*' -not -path './uploads/*' -not -path './scratchpad/*'

.DEFAULT_GOAL := help
.PHONY: help lint test test-unit test-integration smoke migrate migrate-status rollback secrets ci

help: ## Show this help
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) \
	  | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

lint: ## Syntax-check every PHP file
	@$(FIND_PHP) -print0 | xargs -0 -n1 -P4 $(PHP) -l > /dev/null
	@echo "All PHP files parse cleanly."

test: ## Run the whole suite (unit + integration + smoke)
	@bash tests/run.sh

test-unit: ## Unit tests only (no database)
	@$(PHP) tests/unit/run.php

test-integration: ## Integration tests (needs a database)
	@$(PHP) tests/integration/run.php

smoke: ## Read-only smoke test against the configured database
	@$(PHP) tests/smoke.php

migrate: ## Apply pending migrations
	@$(PHP) bin/migrate migrate

migrate-status: ## Show which migrations are applied
	@$(PHP) bin/migrate status

rollback: ## Roll back the most recent migration batch (dev/staging only)
	@$(PHP) bin/migrate rollback

secrets: ## Check that no credentials are staged for commit
	@if git ls-files --error-unmatch .env >/dev/null 2>&1; then \
	  echo "FAIL: .env is tracked by git."; exit 1; fi
	@if git grep -nE "env\('DB_PASS',\s*'.+'\)" -- config.php >/dev/null 2>&1; then \
	  echo "FAIL: config.php has a non-empty DB_PASS fallback."; exit 1; fi
	@echo "No committed secrets found."

ci: lint secrets test ## Everything CI runs, locally
