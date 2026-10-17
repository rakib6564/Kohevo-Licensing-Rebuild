# Branch and worktree status (final, 2026-10-17)

I ran `git fetch --prune origin` in `~/Downloads/Fahim/KOHEVO-STUDIO`; `origin/main` is still `ba4c9a54a5cfc03f2f0f5c388baa5f798dbf3c8a`. Nothing was pushed, merged, deleted or force-written in this pass.

| Ref | SHA | Behind / ahead of `origin/main` | Upstream | Role |
|---|---|---|---|---|
| `fix/secret-handling-gaps` | `6fed518a67cdb630b15debd34f53b77c3cb94698` | 0 / 2 | `origin/fix/secret-handling-gaps`, in sync | **PR #102.** Open, MERGEABLE, CLEAN; CI 3/3 succeeded (MySQL 8.0, MariaDB 10.11, Playwright); 0 reviews, 0 comments; Auto-fix on |
| `fix/licensing-module-guard` | `fd592d3` | 0 / 4 | none (local) | Proposed PR 2: `08f3b99`, `f5154f5`, `188808b`, `fd592d3` |
| `audit/settings-hardening-next` | tip (last code commit `346b48b`; only docs commits after it) | 0 / 12 | none (local) | Proposed PR 3: `e9a1bdf`, `d368b5c`, `b8a6fdc`, `197fe44`, `9ac61ab`, `240caef`, `c926c18`, `346b48b`, then 3 docs commits |
| `audit/settings-secrets-hardening` | `e9a1bdf313e0e6aed1ea99e7217076a96f1122c6` | 0 / 1 | none | Your main checkout. **Untouched** (see below). |
| `integration/final-check` | `8a09f58be2fadc6cafd930b1bf8f06722dfd6c84` | 0 / 15 | none, unset on purpose | Test only: the three PR branches merged onto `main`. **Never push.** |
| `integration/secrets-and-audit` | `59c541ddd1405b7c3a73e072fe8c889282c21c7c` | 0 / 13 | none | Earlier test-only tree, superseded. **Never push.** |

## Your main checkout (`~/Downloads/Fahim/KOHEVO-STUDIO`)

- Branch `audit/settings-secrets-hardening` @ `e9a1bdf`: 0 staged, 40 unstaged, 15 untracked audit files. There are also untracked `vendor/`, `composer.lock` and the two prototype directories.
- The working state is still byte-identical to snapshot tree `7dbc7a1a`. `audit/settings-hardening-next` commit `d368b5c` has exactly that tree, so your follow-up work now also exists as a commit. **The checkout itself was not modified, staged or reset.**
- When the PR is ready, you can keep working from the main checkout (commit there with `review-package/commit-message.txt`), or switch to `audit/settings-hardening-next`, which already contains that commit plus the new fixes.

## Worktrees (all under the session scratchpad except the main checkout)

| Worktree | Branch | State |
|---|---|---|
| `wt-secrets` | `fix/secret-handling-gaps` | clean |
| `wt-guard` | `fix/licensing-module-guard` | clean |
| `wt-hard` | `audit/settings-hardening-next` | clean after the docs commit |
| `wt-final` | `integration/final-check` | clean |
| `wt-integ` | `integration/secrets-and-audit` | clean (superseded) |
| `wt-base` | detached @ `ba4c9a5` | clean; used for base runs |

Untracked `vendor/` and `node_modules/` copies in the scratch worktrees exist only to run tests.
