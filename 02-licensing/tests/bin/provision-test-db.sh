#!/usr/bin/env bash
#
# Provision an isolated test database for this checkout.
#
# WHY A DATABASE PER CHECKOUT, rather than namespaced identifiers in a shared one:
#
# Several integration tests deliberately operate on current_tenant_id() with
# fixed identifiers — IdentityStoreTest, MfaRepositoryTest and
# RbacTenantIsolationTest all do, because testing tenancy behaviour relative to
# the real tenant is the point of them. Namespacing their tenant would change
# what they assert.
#
# So two concurrent runs against one database both INSERT the same credential
# under tenant 1 and collide on a unique key. Measured: identifier namespacing
# alone still produced 2-9 failures per concurrent pair, degrading across rounds.
# Separate databases is the only isolation that holds.
#
# Identifier namespacing (tests/isolation.php) stays, and is complementary: it
# keeps stale rows traceable to the checkout that wrote them, and protects a
# shared database from cross-checkout residue when someone runs without
# provisioning.
#
# cPanel does not grant CREATE DATABASE to the account's MySQL user, so this
# goes through uapi.
#
# Usage:  bash tests/bin/provision-test-db.sh [suffix]
#         suffix defaults to the checkout's isolation namespace.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

if [ ! -f .env ]; then
  echo "No .env in $ROOT — copy one from a provisioned checkout first." >&2
  exit 1
fi

# Read the account prefix and credentials from the existing .env.
set -a; . ./.env; set +a
PREFIX="${DB_USER%%_*}"

SUFFIX="${1:-$(php -r 'require "tests/isolation.php"; echo slate_test_ns();' 2>/dev/null || echo 0)}"
TEST_DB="${PREFIX}_slate_t${SUFFIX}"

echo "Provisioning ${TEST_DB} for ${ROOT}"

if command -v uapi >/dev/null 2>&1; then
  uapi --output=json Mysql create_database name="$TEST_DB" >/dev/null 2>&1 || true
  uapi --output=json Mysql set_privileges_on_database \
       user="$DB_USER" database="$TEST_DB" privileges="ALL PRIVILEGES" >/dev/null 2>&1 || true
else
  # Plain local MySQL/MariaDB (no cPanel) — e.g. a laptop dev setup. GRANT is
  # best-effort: it's a no-op (and harmless) when DB_USER already has it, as
  # root typically does.
  mysql -h "${DB_HOST:-127.0.0.1}" -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} \
    -e "CREATE DATABASE IF NOT EXISTS \`${TEST_DB}\` CHARACTER SET utf8mb4;"
  mysql -h "${DB_HOST:-127.0.0.1}" -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} \
    -e "GRANT ALL PRIVILEGES ON \`${TEST_DB}\`.* TO '${DB_USER}'@'%'; FLUSH PRIVILEGES;" >/dev/null 2>&1 || true
fi

# Point this checkout at it. Never touches DB_USER/DB_PASS, so credentials stay
# in one place.
sed -i "s/^DB_NAME=.*/DB_NAME=${TEST_DB}/" .env

# Schema from migrations, so the test database is built the same way production
# is rather than from a dump that drifts.
php bin/migrate migrate

# The minimum fixture the suite assumes exists. Mirrors what CI seeds; the MFA
# tests throw rather than fail without an active admin, which takes the whole
# run down.
mysql -h "${DB_HOST:-127.0.0.1}" -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$TEST_DB" <<SQL
INSERT INTO tenants (id,name,slug,status) VALUES (1,'Test Tenant','test-tenant','active')
  ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO roles (id,tenant_id,name,slug,description,is_system)
  VALUES (1,1,'Super Admin','super-admin','Test admin',1)
  ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO users (tenant_id,email,password_hash,name,role_id,status)
  VALUES (1,'test-admin@example.test','\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC4WvO75mGxU2Rz1nO','Test Admin',1,'active')
  ON DUPLICATE KEY UPDATE status=VALUES(status), role_id=VALUES(role_id);
INSERT INTO settings (tenant_id,setting_key,setting_value) VALUES (1,'site_name','Slate Test')
  ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
-- Marker tests/guard.php requires. A production database has no reason to carry
-- this row, which is what makes the guard positive identification rather than a
-- denylist that lapses.
INSERT INTO settings (tenant_id,setting_key,setting_value) VALUES (1,'slate_test_database','1')
  ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
INSERT INTO plugins (slug,name,version,status,manifest_json)
  VALUES ('content-builder','Content Builder','1.0.0','active','{"slug":"content-builder","name":"Content Builder","version":"1.0.0"}')
  ON DUPLICATE KEY UPDATE status=VALUES(status), manifest_json=VALUES(manifest_json);
INSERT INTO plugins (slug,name,version,status,manifest_json) VALUES
  ('booking','Booking Plus','1.0.0','active','{"slug":"booking","name":"Booking Plus"}'),
  ('forms','Forms','1.0.0','active','{"slug":"forms","name":"Forms"}'),
  ('membership','Membership','1.0.0','active','{"slug":"membership","name":"Membership"}'),
  ('stripe-payment','Stripe Payment Gateway','1.0.0','active','{"slug":"stripe-payment","name":"Stripe Payment Gateway"}')
  ON DUPLICATE KEY UPDATE status=VALUES(status), manifest_json=VALUES(manifest_json);
SQL

CONTENT_BUILDER_SCHEMA="plugins/content-builder/install.sql"
if [ ! -f "$CONTENT_BUILDER_SCHEMA" ] && [ -f "archive/plugins/content-builder/install.sql" ]; then
  CONTENT_BUILDER_SCHEMA="archive/plugins/content-builder/install.sql"
fi
if [ ! -f "$CONTENT_BUILDER_SCHEMA" ]; then
  echo "Missing content-builder schema: expected plugins/content-builder/install.sql or archive/plugins/content-builder/install.sql" >&2
  exit 1
fi
mysql -h "${DB_HOST:-127.0.0.1}" -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$TEST_DB" < "$CONTENT_BUILDER_SCHEMA"

# Kept in lockstep with .github/workflows/ci.yml's schema steps — this script had
# drifted to content-builder only, which fails every Booking/Forms/Membership
# integration test locally with "table doesn't exist" while CI stayed green.
mysql -h "${DB_HOST:-127.0.0.1}" -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$TEST_DB" < plugins/forms/install.sql
mysql -h "${DB_HOST:-127.0.0.1}" -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$TEST_DB" < plugins/booking/install.sql

# install.sql alone is NOT the schema Booking writes to — party_size,
# discount_cents, gift_applied_cents, stripe_session_id and others exist only as
# ensureColumn() top-ups in Booking.php. This applies them the same way CI does.
php tests/fixtures/booking-schema.php

mysql -h "${DB_HOST:-127.0.0.1}" -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$TEST_DB" < plugins/membership/install.sql
mysql -h "${DB_HOST:-127.0.0.1}" -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$TEST_DB" < plugins/stripe-payment/install.sql
# Content the page-render golden tests expect to find.
RENDER_SEED="tests/fixtures/render-seed.sql"
if [ ! -f "$RENDER_SEED" ] && [ -f "archive/tests/fixtures/render-seed.sql" ]; then
  RENDER_SEED="archive/tests/fixtures/render-seed.sql"
fi
if [ ! -f "$RENDER_SEED" ]; then
  echo "Missing render seed fixture: expected tests/fixtures/render-seed.sql or archive/tests/fixtures/render-seed.sql" >&2
  exit 1
fi
mysql -h "${DB_HOST:-127.0.0.1}" -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$TEST_DB" < "$RENDER_SEED"

echo "Provisioned ${TEST_DB}. This checkout's .env now points at it; the live database is untouched."
