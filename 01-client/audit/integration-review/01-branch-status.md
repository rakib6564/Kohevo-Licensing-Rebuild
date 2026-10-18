# Branch and worktree status (2026-10-18, release-check plus High fixes)

I ran `git fetch origin` in `~/Downloads/Fahim/KOHEVO-STUDIO`; `origin/main` is still `ba4c9a5`. Nothing was pushed, merged, deleted or force-written in this pass. No published history was rewritten.

| Ref | SHA | Behind / ahead of `origin/main` | Upstream | Role |
|---|---|---|---|---|
| `fix/secret-handling-gaps` | `6fed518` | 0 / 2 | `origin/fix/secret-handling-gaps`, in sync | **PR #102.** Open, MERGEABLE; CI 3/3 succeeded; Auto-fix on |
| `fix/licensing-module-guard` | `d77b076` | 0 / 6 | none (local) | Proposed PR 2: `08f3b99`, `f5154f5`, `188808b`, `fd592d3`, `2866452`, `d77b076` |
| `audit/settings-hardening-next` | this commit's branch (last code/test commit `234b4d6`) | 0 / 20+ | none (local) | Proposed PR 3: `e9a1bdf`, `d368b5c`, `b8a6fdc`, `197fe44`, `9ac61ab`, `240caef`, `c926c18`, `346b48b`, `8db616f`, `c56c7be`, `5462c1e`, `19c9a55`, `444c531`, `49c1fd4`, `234b4d6`, plus documentation commits |
| `fix/studio-post-content-xss` | `c19c9d5` | 0 / 1 | none (local) | N1: stored XSS in `theme.post_content` |
| `fix/mcp-settings-allowlist` | `3862f1c` | 0 / 1 | none (local) | N2: MCP settings allow-list (both trees) |
| `fix/studio-template-delete` | `885d23e` | 0 / 1 | none (local) | N3: template delete touches only its page, keeps history |
| `fix/studio-theme-published-conditions` | `72fd527` | 0 / 1 | none (local) | N4: Theme Builder uses published settings |
| `fix/studio-admin-permissions` | `4aa8550` | 0 / 1 | none (local) | N5: Studio permission levels on templates/content pages |
| `integration/release-check-highs` | `5e4963c` (code), plus docs merges | — | none, on purpose | Test only: release-check plus the five fixes. **Never push** |
| `audit/settings-secrets-hardening` | `e9a1bdf` | 0 / 1 | none | Your main checkout. **Untouched** |
| `integration/release-check` | `99ba13e` (code), plus a later docs-only merge | 0 / 34+ | none, on purpose | Test only: the three PR branches merged onto `main`. **Never push** |
| `integration/final-check` | `71d4e83` | — | none | Earlier test tree, superseded. **Never push** |
| `integration/secrets-and-audit` | `59c541d` | — | none | Earlier test tree, superseded. **Never push** |

## Checkpoints (created before any history-sensitive step)

| Ref | Points to |
|---|---|
| `refs/backup/checkpoint-2026-10-17-late/audit-settings-hardening-next` | `7d4a32a` |
| `refs/backup/checkpoint-2026-10-17-late/fix-licensing-module-guard` | `fd592d3` |
| `refs/backup/checkpoint-2026-10-17-late/integration-final-check` | `71d4e83` |
| `refs/backup/settings-hardening-next-before-split` | `29d3633` (before the 2026-10-17 commit split; trees identical) |

All new work in this pass was added as new commits on top of those heads; nothing was rewritten.

## Your main checkout (`~/Downloads/Fahim/KOHEVO-STUDIO`)

- Branch `audit/settings-secrets-hardening` at `e9a1bdf`: 0 staged, 40 unstaged and 15 untracked audit files, plus untracked `vendor/`, `composer.lock` and the two prototype directories. No stashes.
- No file in it was modified during this pass. The only file written there is the release ZIP at the path you asked for, which `.gitignore` (`*.zip`) excludes. Its working state is also preserved as commit `d368b5c` on `audit/settings-hardening-next`.

## Worktrees (session scratchpad, except the main checkout)

| Worktree | Branch | State |
|---|---|---|
| `wt-secrets` | `fix/secret-handling-gaps` | clean |
| `wt-guard` | `fix/licensing-module-guard` | clean |
| `wt-hard` | `audit/settings-hardening-next` | clean after the docs commit |
| `wt-rel` | `integration/release-check` | clean |
| `wt-relh` | `integration/release-check-highs` | clean |
| `wt-n1` … `wt-n5` | the five fix branches | clean |
| `wt-final`, `wt-integ` | superseded test trees | clean |
| `wt-base` | detached at `ba4c9a5` | clean; used for base runs |

Untracked `vendor/` and `node_modules/` copies in the scratch worktrees exist only to run tests.
