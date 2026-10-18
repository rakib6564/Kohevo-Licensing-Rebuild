# Release-readiness report, 2026-10-18 (release-check plus High fixes)

## Verdict

| Scope | Verdict |
|---|---|
| The three PRs (#102, licensing ModuleGuard, settings hardening) | **READY WITH EXCEPTIONS.** Code complete and tested on the combined tree; the exceptions are owner decisions and pushing PR 2 and PR 3 so their CI runs |
| A product release from `main` after these PRs | **READY WITH EXCEPTIONS once the five High-fix branches are merged too.** The fresh audit found five High issues already on `origin/main` (N1–N5). Each now has its own fix branch with a test that fails without it. All eight branches pass together on `integration/release-check-highs` (see `04-…`). Medium and Low audit findings remain open (`12-…`) |

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
| Tests order-independent, state restored, no bypass, no silent skip | ✅ both trees, 2 shuffle seeds each, same results as alphabetical; three order bugs fixed (`19c9a55`, `444c531`, `234b4d6`); 2 remaining failures are R5 (decision) |
| Mutation tests | ✅ 15/15 (release set) and 11/11 (N1–N5) caught on the combined tree |
| High findings N1–N5 | ✅ fixed on separate local branches (`pr-high-fixes.md`); ⏳ not pushed |
| Studio Builder audit (widgets/controls, containers/layout, Theme Builder, templates, tokens, settings) | ✅ written: `studio/`, `settings/`, register `12-…`. Source-read; nothing run in a browser |
| Required CI on each PR head | ⚠️ #102 only; PR 2, PR 3 and the five High-fix branches are local |
| Live providers (Stripe, Google, Microsoft, Twilio, AI, translation) | ⚠️ not called by any test; sandbox plan in `11-…` §4 |
| Studio Playwright on the combined tree | ⚠️ not run locally (drops `slate_sbx_e2e`) |
| No unauthorised push, merge or deploy | ✅ the only push was #102, on your instruction |

## Decisions needed

1. **D1:** approve per-plan gating in central.
2. **R2:** confirm that a retired plan keeps granting its modules to tenants already on it.
3. **R5:** restore the central dashboard sections removed by `0d48565`, or retire `DashboardMetricsTest`.
4. **Pushes:** authorise pushing `fix/licensing-module-guard`, `audit/settings-hardening-next` and the five High-fix branches, and opening their PRs (descriptions in `pr-*.md`).
5. **N1–N5 behaviour changes:** confirm that MCP may only change general settings, and that template delete needs Studio admin and publish/unpublish needs Studio publish.

## PR order

1. **#102:** merge.
2. **`fix/licensing-module-guard`:** push, open PR, let CI run; before deploying, assign plans (`11-…` §1).
3. **`audit/settings-hardening-next`:** push, open PR after #102 merges; resolve the overlap as in `03-…`.
4. The five High-fix branches (`fix/studio-post-content-xss`, `fix/mcp-settings-allowlist`, `fix/studio-template-delete`, `fix/studio-theme-published-conditions`, `fix/studio-admin-permissions`): push and open as five PRs; they are independent of each other and of 1–3. N3 and N5 merge cleanly together.

## Single next action

Approve D1 and authorise pushing the seven local branches (PR 2, PR 3 and the five High fixes), so their CI, including Playwright, runs on the real PR heads.
