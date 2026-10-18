#!/usr/bin/env bash
# usage: run-suites.sh <repo-root> <logdir>
R="$1"; L="$2"; mkdir -p "$L"
run() { name="$1"; dir="$2"; shift 2; ( cd "$dir" && "$@" ) >"$L/$name.log" 2>&1; echo "$name exit=$?" >> "$L/summary.txt"; }
: > "$L/summary.txt"
run c-unit        "$R" php 01-client/tests/unit/run.php
run l-unit        "$R" php 02-licensing/tests/unit/run.php
run c-smoke       "$R" php 01-client/tests/smoke.php
run l-smoke       "$R" php 02-licensing/tests/smoke.php
run c-integration "$R" php 01-client/tests/integration/run.php
run l-integration "$R" php 02-licensing/tests/integration/run.php
run c-studio-unit "$R" php 01-client/tests/run-studio-builder.php unit
run c-studio-int  "$R" php 01-client/tests/run-studio-builder.php integration
run ui            "$R/01-client/plugins/studio-builder/ui" npm test
echo DONE >> "$L/summary.txt"
