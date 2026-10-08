#!/usr/bin/env bash
#
# Build (or rebuild) the local sandbox the Playwright suites run against.
#
#   bash e2e/sandbox.sh [sandbox-dir] [port]
#
# Copies 01-client to <sandbox-dir>/site, writes a throw-away .env (own database
# `slate_sbx_e2e`, test licence key) and provisions it. The database is DROPPED and
# recreated each run. Then start it with:
#
#   php -S localhost:<port> -t <sandbox-dir>/site <sandbox-dir>/site/dev-server.php
#
# (or let `npm run test:e2e` start it: set SBX_DIR=<sandbox-dir>/site.)
#
# The database defaults to a local root account with no password; override with
# SBX_DB_HOST / SBX_DB_PORT / SBX_DB_USER / SBX_DB_PASS (CI uses a MySQL service).
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
CLIENT="$(cd "$HERE/../../../.." && pwd)"
DIR="${1:-${TMPDIR:-/tmp}/kohevo-studio-sandbox}"
PORT="${2:-8100}"
SITE="$DIR/site"

mkdir -p "$SITE"
rsync -a --delete \
  --exclude node_modules --exclude .env --exclude .installed --exclude storage \
  --exclude scratch --exclude .git --exclude 'plugins/studio-builder/ui/e2e/.auth' \
  --exclude 'plugins/studio-builder/ui/test-results' \
  "$CLIENT/" "$SITE/"

PUBLIC_KEY="$(cd "$SITE" && php -r 'define("SLATE_TESTING", true); require "tests/support/license_signing.php"; echo license_test_public_key();')"
cat > "$SITE/.env" <<ENV
APP_URL=http://localhost:$PORT
TENANT_ID=1
APP_SECRET=sandbox-secret-not-for-production
CRON_SECRET=sandbox-cron-secret
DB_HOST=${SBX_DB_HOST:-127.0.0.1}
DB_PORT=${SBX_DB_PORT:-}
DB_NAME=slate_sbx_e2e
DB_USER=${SBX_DB_USER:-root}
DB_PASS=${SBX_DB_PASS:-}
DB_CHARSET=utf8mb4
LICENSE_SERVER_URL=https://license.test
LICENSE_SERVER_PUBLIC_KEY=$PUBLIC_KEY
LICENSE_PRODUCT=kohevo
LICENSE_KEY=test-key
LICENSE_SYNC_INTERVAL=86400
ENV

(cd "$SITE" && php plugins/studio-builder/ui/e2e/provision.php)
echo "sandbox ready: SBX_DIR=$SITE  (port $PORT)"
