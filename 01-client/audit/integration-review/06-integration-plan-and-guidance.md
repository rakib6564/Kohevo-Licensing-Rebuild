# Integration plan and PR sequence (final)

Three focused PRs, merged in this order. The combination was tested as `integration/final-check`, which is local only and must never be pushed.

| # | Branch | Head | Scope | Status | Needs from you |
|---|---|---|---|---|---|
| 1 | `fix/secret-handling-gaps` (**PR #102**) | `6fed518` | SMTP OAuth sealing, Multilang key, AI SSRF vetting and pinning (28 files, both trees) | Open; CI 3/3 green including Playwright; CLEAN; no reviews yet | Review and **merge** |
| 2 | `fix/licensing-module-guard` | `fd592d3` | Central `ModuleGuard`, test tenant plan helper, plan preflight and assignment CLIs (13 files, `02-licensing` only) | Local; ready | **Approve D1**, **push**, open the PR (body: `pr-licensing-module-guard.md`) |
| 3 | `audit/settings-hardening-next` | tip (last code commit `346b48b`; only docs commits after it) | Audit work (`e9a1bdf` and `d368b5c`), encrypt-first saves, rekey CLI, test hardening, licensing test helpers, corrected inventory and reports | Local; ready after #102 lands | **Push** and open the PR (body: `pr-settings-hardening.md`); merge `main` in once #102 has merged |

PRs 2 and 3 don't touch the same files. PR 3 conflicts with PR #102 only in `lang/fr.php` and `plugins/mcp-gateway/admin/ai-settings.php`, in both trees. The resolution recorded in `03-conflict-map.md` and tested in `integration/final-check` is: vet the URL, then seal the key, and write only if both pass; keep both sets of French strings.

## Deploy steps (central server)

1. Merge PR 2. Before deploying: run `php bin/check-tenant-plans.php`. For every tenant it reports as denied, run `php bin/assign-tenant-plan.php <tenant> <plan>`, starting with the operator tenant. Re-run the check until it exits 0.
2. Confirm production `.env` does not set `APP_ENV=testing`, because that would bypass `ModuleGuard`.
3. To rotate `APP_SECRET` later:
   1. Back up the database.
   2. Set the new value in `APP_SECRET`.
   3. Run `OLD_APP_SECRET=… php bin/rekey-secrets.php` (dry run).
   4. Run the same command with `--apply`.
   5. Keep the backup file it writes.

   Roll back by running it in reverse.

## Rollback

| PR | Rollback |
|---|---|
| #102 | Revert. Envelopes stay readable, but any refused-but-intended AI URL needs re-entering. |
| 2 | Revert. Central Forms, Booking and Membership go back to returning 500. |
| 3 | Revert. Forms CAPTCHA envelopes can't be read by the old code, so affected forms fail CAPTCHA until each secret is re-entered. The rekey CLI changes nothing unless `--apply` is passed. |

## Main checkout

Your 40 uncommitted changes are untouched. Commit `d368b5c` on `audit/settings-hardening-next` holds the same tree. When you're ready, either switch the main checkout to that branch, or commit there using `review-package/commit-message.txt`; the result is identical.

## Guidance for future secret settings

1. **Write-only field:** blank means keep the stored value; an explicit checkbox means clear it.
2. **Seal first:** call `slate_seal_posted_secrets()`, or `SmtpOAuth::sealSecrets()` for SMTP OAuth. If it returns null, show a field-level error and write nothing.
3. **Read through a decrypt helper:** pass legacy plaintext through unchanged and return null when decryption fails. Never log the value.
4. **Outbound URLs set by an admin:** check them with `AiProviderClient::vetBaseUrl()` both when they're saved and when they're used. Pin the request to the vetted address and turn off redirects.
5. **Tests:** run the real page in a child process, with and without `APP_SECRET`. Snapshot and restore every setting prefix the form writes. Use precondition assertions, never skip-as-pass. Add French strings, because the translation gate fails without them.
