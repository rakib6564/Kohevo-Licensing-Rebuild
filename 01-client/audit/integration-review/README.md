# Integration review, 2026-10-17 (release-check)

Base is `origin/main` `ba4c9a5`. The combined tree tested is `integration/release-check` (local, test only, never pushed): PR #102 `6fed518` plus `audit/settings-hardening-next` `49c1fd4` plus `fix/licensing-module-guard` `d77b076`. The earlier combined tree `integration/final-check` is superseded. The five High-fix branches were tested on top of it as `integration/release-check-highs` (also local, never pushed).

| File | Contents |
|---|---|
| `01-branch-status.md` | Worktrees, branches, SHAs, upstreams, and the inventory of uncommitted audit work |
| `02-pr-review-findings.md` | Review of `fix/secret-handling-gaps`: verdict per requirement, findings F1–F7 |
| `03-conflict-map.md` | Where the branches overlap, which conflicts came up, and how they were resolved |
| `04-test-results.md` | Integrated vs base results, the remaining licensing failures, mutation tests, what was not run |
| `05-remaining-risks.md` | What decides the release: closed risks, owner decisions (D1, R2, R5), new High findings N1–N5 |
| `06-integration-plan-and-guidance.md` | Merge sequence, PR recommendation, the pattern for secret settings |
| `07-inventory-validation.md` | Validation of the settings and widget inventory CSV |
| `08-commands.md` | Commands executed |
| `09-d1-decision-and-moduleguard-review.md` | D1 policy recommendation, evidence, ModuleGuard review |
| `10-final-release-readiness.md` | Verdict, status per area, PR order, next action |
| `11-runbooks.md` | Deploying plan gating, `APP_SECRET` rotation and rollback, PR rollback, live-provider sandbox checks |
| `12-findings-register.md` | Every finding with ID, severity, status and evidence (security, licensing, test infrastructure, Studio Builder, settings) |
| `studio/` | Fresh Studio Builder audit: widgets and controls (60 types, `studio-widget-controls.csv` 333 rows), containers and layout, design tokens (`studio-design-tokens.csv` 60 rows), Theme Builder, templates import/export |
| `settings/` | Full settings inventory (`settings-full-inventory.csv`, 482 rows across both trees) and audit; `tools/` rebuilds the CSV |
| `inventory-claims-verify.py`, `verification/` | Independent check of the widget matrix and the inventory's claims, with per-row output |
| `pr-secret-handling-gaps.md` | PR #102 description, as opened |
| `pr-licensing-module-guard.md` | PR 2 description, ready to paste |
| `pr-settings-hardening.md` | PR 3 description, ready to paste |
| `pr-high-fixes.md` | Descriptions for the five High-fix branches (N1–N5), ready to paste |
| `csv-row-verification-integrated.csv` | Result for each inventory row |
| `test-logs/release/` | Runs on `integration/release-check`: full matrix, per-file, CI subset, shuffled orders, mutations (see `04-…`) |
| `test-logs/release-highs/` | Runs on `integration/release-check-highs` (release tree plus N1–N5): full matrix, shuffled orders, 11 + 15 mutations |
| `test-logs/final/` | Earlier runs r8–r10 on `integration/final-check` |
| `test-logs/` | Summaries and runner scripts for each suite (base, integrated, integrated after the guard merge, combined at `a19abf0`), the D1 probes, the call-site list, and worktree status |
