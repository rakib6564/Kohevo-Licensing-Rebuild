# Release-readiness report, 2026-10-17 (release-check)

## Verdict

| Scope | Verdict |
|---|---|
| The three PRs (#102, licensing ModuleGuard, settings hardening) | **READY WITH EXCEPTIONS.** Code complete and tested on the combined tree; the exceptions are owner decisions and pushing PR 2 and PR 3 so their CI runs |
| A product release from `main` after these PRs | **NOT READY.** The fresh audit found five High issues that already exist on `origin/main` (stored XSS, MCP settings write, template delete, draft conditions going live, permission gaps). They are not caused by these PRs, but should be fixed before a release that advertises the Studio Builder to editors who are not fully trusted |

## Checklist

| Item | Status |
|---|---|
| Branch and worktree state verified; your work preserved | ✅ `01-branch-status.md`. Main checkout untouched |
| PR #102 CI green and diff reviewed | ✅ CI 3/3 at `6fed518`; review in `02-…` |
| Secrets encrypted at rest; no plaintext fallback; blank keeps; Clear removes only that secret; legacy values readable | ✅ `05-…` S1–S8; `SettingsEncryptFirstTest`; mutations caught |
| `APP_SECRET` rotation: dry run, abort before writes, transactional, ciphertext backup 0600, restore and reverse rollback, rerunnable, non-InnoDB refused | ✅ `RekeySecretsCliTest` (7); runbook `11-…` §2. **Not run on production** |
| ModuleGuard: 60 calls in 56 files, denial on real pages | ✅ `CentralModuleGuardTest`; `09-…` |
| Plan assignment path (Tenants screen, CLI, preflight, warning) | ✅ `TenantPlanAdminTest`. ⏳ Plans must be assigned on the server before deploying PR 2 |
| `APP_ENV=testing` web bypass | ✅ closed in both trees |
| Licensing tests: duplicates removed, order-independent, state restored, no bypass, no silent skip | ✅ shuffled runs (2 seeds) match the alphabetical run; 2 remaining failures are R5 (decision) |
| Mutation tests | ✅ 15/15 caught on the release tree (`test-logs/release/mutations-rel.txt`) |
| Studio Builder audit (widgets/controls, containers/layout, Theme Builder, templates, tokens, settings) | ✅ written: `studio/`, `settings/`, register `12-…`. Source-read; nothing run in a browser |
| Required CI on each PR head | ⚠️ #102 only; PRs 2 and 3 are local |
| Live providers (Stripe, Google, Microsoft, Twilio, AI, translation) | ⚠️ not called by any test; sandbox plan in `11-…` §4 |
| Studio Playwright on the combined tree | ⚠️ not run locally (drops `slate_sbx_e2e`) |
| No unauthorised push, merge or deploy | ✅ the only push was #102, on your instruction |

## Decisions needed

1. **D1:** approve per-plan gating in central.
2. **R2:** confirm that a retired plan keeps granting its modules to tenants already on it.
3. **R5:** restore the central dashboard sections removed by `0d48565`, or retire `DashboardMetricsTest`.
4. **Pushes:** authorise pushing `fix/licensing-module-guard` and `audit/settings-hardening-next` and opening their PRs (descriptions in `pr-*.md`).
5. **Follow-up fixes N1–N5:** whether to fix them in separate PRs before release (recommended).

## PR order

1. **#102:** merge.
2. **`fix/licensing-module-guard`:** push, open PR, let CI run; before deploying, assign plans (`11-…` §1).
3. **`audit/settings-hardening-next`:** push, open PR after #102 merges; resolve the overlap as in `03-…`.
4. Separate small PRs for N1–N5.

## Single next action

Approve D1 and authorise pushing the two local branches, so their CI (including Playwright) runs on the real PR heads.
