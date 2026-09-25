#!/usr/bin/env bash
# Slate test runner (Phase 0). Runs the dependency-free smoke suite.
# Usage: bash tests/run.sh   (exit 0 = pass, non-zero = fail)
set -euo pipefail
cd "$(dirname "$0")/.."
echo "== Slate unit tests =="
php tests/unit/run.php
echo "== Slate integration tests =="
php tests/integration/run.php
echo "== Slate smoke tests =="
php tests/smoke.php
# JS syntax gate. Skipped when node is absent — most shared hosts have no
# node, and the PHP suites must stay runnable there (ADR-0003).
if command -v node >/dev/null 2>&1; then
  # Syntax gate for every shipped script. php -l obviously does not read .js,
  # and a syntax error in admin JS is a silently dead panel, found by a user.
  echo "== Slate JS syntax check =="
  find plugins -name '*.js' -not -path '*/node_modules/*' -print0 \
    | xargs -0 -r -n1 node --check
  echo "   all scripts parse"
else
  echo "== Slate JS syntax check == (skipped: node not found)"
fi
