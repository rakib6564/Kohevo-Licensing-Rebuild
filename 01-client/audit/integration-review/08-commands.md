# Commands executed (this pass)

`$S` below is the session scratchpad. The main checkout is `~/Downloads/Fahim/KOHEVO-STUDIO`.

```
git fetch --prune origin
git worktree list --porcelain; git branch -vv
gh pr list --repo rakib6564/Kohevo-Licensing-Rebuild --head <branch> --state all
# Snapshot the uncommitted audit work without touching the real index
GIT_INDEX_FILE=$S/r4/tmp-index git read-tree HEAD
GIT_INDEX_FILE=… git add -A -- 01-client 02-licensing ':!*/vendor' ':!*/composer.lock'
git write-tree; git commit-tree <tree> -p HEAD -m "snapshot…"     # -> 8788f2c (dangling)
git diff --binary HEAD 8788f2c > $S/r4/audit-uncommitted-snapshot.patch
# PR review
git show 45eff7a -- <files>
php -r '…AiProviderClient::vetBaseUrl(…)…'                          # edge-case probes, injected DNS and real DNS
# Hardening commit on fix/secret-handling-gaps (wt-secrets)
php tests/unit/run.php (both trees); php scripts/check-i18n.php
git commit                                                            # -> 6fed518 (not pushed)
# Conflict map
git merge-tree --write-tree --name-only 8788f2c 6fed518
# Integration worktree
git worktree add -b integration/secrets-and-audit $S/wt-integ origin/main; git branch --unset-upstream
git merge --no-ff 6fed518; git merge --no-ff e9a1bdf                  # fr.php conflicts resolved by hand (keep both key sets)
git cherry-pick -n 8788f2c; git commit                                # -> a462cf6
# ModuleGuard
git worktree add -b fix/licensing-module-guard $S/wt-guard origin/main
php -r 'chdir("02-licensing"); require "config.php"; var_dump(class_exists("ModuleGuard"));'   # base: false
git commit                                                            # -> 08f3b99 (not pushed)
git -C wt-integ merge --no-ff 08f3b99                                 # -> e11a69a
# Tests (logs in test-logs/)
git worktree add --detach $S/wt-base origin/main
run-suites.sh <tree> <logdir>          # unit, smoke, integration, Studio subsets, UI (npm test)
run-lic-per-file.sh <tree> <log>       # 02-licensing integration, one process per file
run-lic-ci.sh <tree>                   # the 02-licensing commands from ci.yml
php scripts/check-i18n.php
# Mutation checks: break the code, run the test, restore (9 of 9 caught)
python3 -I csv-verify.py 01-client <out>
```

Nothing was pushed, merged, deployed or deleted. No database migration was run, apart from the existing test suites running against the test database.

## Final pass (2026-10-17)

```
git fetch --prune origin; git worktree list; gh pr view 102 --json …; gh api …/pulls/102/comments
# ModuleGuard branch (wt-guard): tests/support/tenant-plan.php, 6 test files, CentralModuleGuardTest,
#   bin/check-tenant-plans.php, bin/assign-tenant-plan.php, CentralTenantPlanCliTest  -> f5154f5, 188808b, fd592d3
# Hardening branch: git worktree add -b audit/settings-hardening-next $S/wt-hard e9a1bdf
git cherry-pick -n 8788f2c; git commit -F commit-message.txt        # -> d368b5c (tree == 7dbc7a1a)
#   encrypt-first saves, tests      -> b8a6fdc, 9ac61ab, 240caef
#   rekey CLI                        -> 197fe44, 17f3a43
#   licensing test helpers           -> 938c4d0
# Combined tree: git worktree add -b integration/final-check $S/wt-final origin/main
git merge --no-ff 6fed518; git merge --no-ff 197fe44   # ai-settings.php and fr.php resolved by hand (03-…)
git merge --no-ff 188808b; … 240caef; … 17f3a43; … fd592d3
run-final.sh (r8), run-r9.sh (r9), licensing single + per-file + CI (r10), 12 mutations
python3 -I review-package/csv-verify.py 01-client <out>; python3 -I integration-review/inventory-claims-verify.py . <out>
# Test-DB repair: 27 settings rows written by my own test, backed up to JSON, then deleted
```
No push, merge, deploy, branch deletion or migration against a non-test database was done.

> **History note:** on 2026-10-17 the hardening branch's mis-scoped commit `938c4d0` (test helpers plus 74 documentation files) was split. Its code and that of `17f3a43` are now `c926c18` and `346b48b`. The trees are identical, and the old commits stay reachable through `refs/backup/settings-hardening-next-before-split`. The combined tree tested here merged the old SHAs; its code is byte-identical to the current branch heads (`git diff ed90282 <final> -- ':!01-client/audit' ':!01-client/Claude'` is empty).
