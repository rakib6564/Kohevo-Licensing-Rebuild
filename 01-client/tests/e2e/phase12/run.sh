#!/bin/bash
# Phase 12 end-to-end harness: real php -S servers for a throwaway copy of the
# central server (:8091) and the client (:8092) against two FRESH databases
# (p12_central, p12_client — dropped and recreated). Never touches the
# checkout's own .env, .installed or test databases.
#
#   P12_WORKDIR   work dir for the app copies (default: $TMPDIR/kohevo-p12-e2e)
#   P12_DB_USER / P12_DB_PASS   MySQL credentials on 127.0.0.1 (default root / empty)
#
# Usage: 01-client/tests/e2e/phase12/run.sh
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$HERE/../../../.." && pwd)"
export P12_WORKDIR="${P12_WORKDIR:-${TMPDIR:-/tmp}/kohevo-p12-e2e}"
export P12_DB_USER="${P12_DB_USER:-root}" P12_DB_PASS="${P12_DB_PASS:-}"
W="$P12_WORKDIR"
MYSQL=(mysql -h127.0.0.1 -u"$P12_DB_USER")
[ -n "$P12_DB_PASS" ] && MYSQL+=(-p"$P12_DB_PASS")

stop() { pkill -f "php -S 127.0.0.1:809[12]" 2>/dev/null || true; }
trap stop EXIT
stop
rm -rf "$W" && mkdir -p "$W"
rsync -a --exclude .env --exclude .installed --exclude 'data/*.log' --exclude tests "$REPO/02-licensing/" "$W/central/"
rsync -a --exclude .env --exclude .installed --exclude 'data/*.log' --exclude tests "$REPO/01-client/" "$W/client/"
mkdir -p "$W/central/tests/fixtures"
cp "$REPO/02-licensing/tests/guard.php" "$W/central/tests/"
cp "$REPO/02-licensing/tests/fixtures/phase10-central.php" "$W/central/tests/fixtures/"
cp "$HERE/central-modules-fixture.php" "$W/central/tests/fixtures/p12-modules.php"

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS p12_central; DROP DATABASE IF EXISTS p12_client;
  CREATE DATABASE p12_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE DATABASE p12_client CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
cat > "$W/central/.env" <<ENV
APP_URL=http://127.0.0.1:8091
TENANT_ID=1
APP_SECRET=p12-central-secret-$(openssl rand -hex 16)
CRON_SECRET=p12-cron-$(openssl rand -hex 8)
DB_HOST=127.0.0.1
DB_NAME=p12_central
DB_USER=$P12_DB_USER
DB_PASS=$P12_DB_PASS
DB_CHARSET=utf8mb4
ENV
php "$W/central/bin/migrate" migrate > "$W/central-migrate.log"
"${MYSQL[@]}" p12_central -e "INSERT INTO settings (tenant_id,setting_key,setting_value) VALUES (1,'site_name','Central'),(1,'slate_test_database','1');
  INSERT INTO plugins (slug,name,version,status,manifest_json) VALUES ('licensing','Licensing','1.0.0','active','{\"slug\":\"licensing\",\"name\":\"Licensing\"}');"
# The production key generator, then the public key the client build embeds.
(cd "$W/central" && php bin/licensing-generate-keys.php > /dev/null)
(cd "$W/central" && php -r 'require "config.php"; echo LicensingAPI::signingPublicKey();') > "$W/pubkey.txt"

(cd "$W/central" && nohup php -S 127.0.0.1:8091 dev-server.php > "$W/central-server.log" 2>&1 < /dev/null &)
(cd "$W/client" && LICENSE_SERVER_URL=http://127.0.0.1:8091 LICENSE_SERVER_PUBLIC_KEY="$(cat "$W/pubkey.txt")" LICENSE_PRODUCT=kohevo \
  nohup php -S 127.0.0.1:8092 dev-server.php > "$W/client-server.log" 2>&1 < /dev/null &)
sleep 1

status=0
for s in scenario_install scenario_runtime scenario_central_authz scenario_expired_activation scenario_reinstall scenario_stress; do
  echo "### $s"
  php "$HERE/$s.php" || status=1
done
echo "### perf_client (informational)"
php "$HERE/scenario_install.php" > /dev/null && php "$HERE/perf_client.php" || true
exit $status
