#!/usr/bin/env bash
# usage: run-lic-ci.sh <repo-root>  — the exact 02-licensing commands from .github/workflows/ci.yml
cd "$1" || exit 2
B="require '02-licensing/config.php'; require '02-licensing/tests/guard.php'; slate_require_test_database(); require '02-licensing/tests/unit/harness.php';"
U="require '02-licensing/tests/unit/harness.php';"
run() { label="$1"; shift; out=$("$@" 2>&1); ec=$?; echo "$label exit=$ec $(printf '%s\n' "$out" | grep -E '^# passed|passed' | tail -1)"; printf '%s\n' "$out" | grep -E '^FAIL|Fatal' | sed 's/^/    /' | cut -c1-200; }
run smoke php 02-licensing/tests/smoke.php
run RemoteLicenseClientTest php -r "$U require '02-licensing/tests/unit/RemoteLicenseClientTest.php'; exit(unit_summary());"
run LicensingSignatureTest php -r "$U require '02-licensing/tests/unit/LicensingSignatureTest.php'; exit(unit_summary());"
run run-phase2-licensing php 02-licensing/tests/run-phase2-licensing.php
run LicensingPluginSchemaTest php -r "$B require '02-licensing/tests/integration/LicensingPluginSchemaTest.php'; exit(unit_summary());"
run Schema+CentralLicensingFoundation php -r "$B require '02-licensing/tests/integration/LicensingPluginSchemaTest.php'; require '02-licensing/tests/integration/CentralLicensingFoundationTest.php'; exit(unit_summary());"
run SlateLicenseCacheStoreTest php -r "$B require '02-licensing/tests/integration/SlateLicenseCacheStoreTest.php'; exit(unit_summary());"
run LicensingQaFixRound2Test php -r "$B require '02-licensing/tests/integration/LicensingQaFixRound2Test.php'; exit(unit_summary());"
run Phase10CheckInSyncTest php -r "$B require '02-licensing/tests/integration/Phase10CheckInSyncTest.php'; exit(unit_summary());"
run Phase13LegacyAdminTest php -r "$B require '02-licensing/tests/integration/Phase13LegacyAdminTest.php'; exit(unit_summary());"
run run-phase2-race php 02-licensing/tests/run-phase2-race.php
