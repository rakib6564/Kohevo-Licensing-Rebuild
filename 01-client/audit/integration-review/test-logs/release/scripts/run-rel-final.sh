#!/usr/bin/env bash
S=<scratch>; R=$S/wt-rel; L=$S/logs/r15; rm -rf $L; mkdir -p $L
echo "tree $(git -C $R rev-parse HEAD)" > $L/tree.txt
bash $S/run-suites.sh $R $L
bash $S/run-lic-perfile.sh $R $L/l-int-perfile.txt
bash $S/run-lic-ci.sh $R > $L/l-ci.txt 2>&1
cd $R
php $S/r6/run-shuffled.php 02-licensing 20261017 > $L/l-int-shuffled.log 2>&1; echo "l-int-shuffled exit=$?" >> $L/summary.txt
php $S/r6/run-shuffled.php 01-client 20261017 > $L/c-int-shuffled.log 2>&1; echo "c-int-shuffled exit=$?" >> $L/summary.txt
php $S/r6/run-shuffled.php 02-licensing 7 > $L/l-int-shuffled-7.log 2>&1; echo "l-int-shuffled-7 exit=$?" >> $L/summary.txt
php $S/r6/run-shuffled.php 01-client 7 > $L/c-int-shuffled-7.log 2>&1; echo "c-int-shuffled-7 exit=$?" >> $L/summary.txt
php scripts/check-i18n.php > $L/i18n.txt 2>&1
git diff --name-only origin/main HEAD -- '*.php' | xargs -n1 php -l 2>&1 | grep -v '^No syntax' > $L/lint-errors.txt; echo "lint-error-lines: $(wc -l < $L/lint-errors.txt)" >> $L/summary.txt
git diff --name-only origin/main HEAD -- '*.js' '*.mjs' | while read f; do node --check "$f" && echo "ok $f"; done > $L/js-check.txt 2>&1
(cd 01-client && php bin/anti-drift.php; cd ../02-licensing && php bin/anti-drift.php) > $L/anti-drift.txt 2>&1
echo ALLDONE > $L/done
