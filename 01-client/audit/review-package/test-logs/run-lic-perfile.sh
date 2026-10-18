#!/usr/bin/env bash
# usage: run-lic-perfile.sh <repo-root> <logfile>  — each 02-licensing integration file in its own process
R="$1"; OUT="$2"; : > "$OUT"
cd "$R"
for f in 02-licensing/tests/integration/*Test.php; do
  n=$(basename "$f" .php)
  res=$(php -r "require '02-licensing/config.php'; require '02-licensing/tests/guard.php'; slate_require_test_database(); require '02-licensing/tests/unit/harness.php'; require '$f'; exit(unit_summary());" 2>&1)
  ec=$?
  sum=$(printf '%s\n' "$res" | grep -E '^# passed' | tail -1)
  echo "$n exit=$ec ${sum:-NO-SUMMARY}" >> "$OUT"
  printf '%s\n' "$res" | grep -E '^FAIL|Fatal' | sed "s/^/  [$n] /" | cut -c1-260 >> "$OUT"
done
