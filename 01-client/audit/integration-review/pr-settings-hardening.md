## fix(settings): settings and secrets hardening (audit follow-through)

Branch `audit/settings-hardening-next`. It is based on `e9a1bdf` (the existing local `audit/settings-secrets-hardening` commit) and adds 3 commits. Against `origin/main`, the code changes come to 69 files, +2,418 / −401; audit reports are counted separately.

| Commit | Contents |
|---|---|
| `e9a1bdf` | Write-only secrets, encryption that fails closed, bounded settings, timezone ownership, brand tokens. |
| `d368b5c` | The audit follow-up that was uncommitted in the main checkout, committed **byte-identical**: its tree equals snapshot `7dbc7a1a`. It covers Forms CAPTCHA encryption, Stripe Disconnect clearing both modes, removal of the payment test seam, test fixes, and the corrected inventory, matrix and reports. |
| `b8a6fdc` | Encrypt every secret before saving a form (Backups, Booking, AI connection). Tests no longer skip silently. |
| `197fe44` | `bin/rekey-secrets.php` for `APP_SECRET` rotation. |

### Security changes

- **Encrypt first.** `slate_seal_posted_secrets()` encrypts every secret a form posts before anything is written. If any secret can't be encrypted, nothing on the form is saved and the page says so; previously the other fields were saved and a "saved, but…" message was shown. This uses the same pattern as the SMTP OAuth callback.
- **`APP_SECRET` rotation.** Run `OLD_APP_SECRET=… php bin/rekey-secrets.php [--apply]`. It checks every `enc:v1` value in every text column of every table with an `id` key:
  - values that already open with the new secret are left alone;
  - values that open with the old secret are re-encrypted;
  - if any value opens with neither, it stops before writing anything.

  It is a dry run by default. With `--apply` it writes in a single transaction, never prints values, and writes an audit-log entry.
- **Forms CAPTCHA, Stripe Disconnect, test seam:** see `d368b5c`.

### Tests

| Test | What it covers | Result |
|---|---|---|
| `SettingsEncryptFirstTest` | The three real pages, run with and without `APP_SECRET` | Fails on the old code (3/6) |
| `SealPostedSecretsTest`, `ReencryptEnvelopeTest` | Unit tests, both trees | Pass |
| `RekeySecretsCliTest` | The real CLI: dry run, apply, and the unreadable-value stop | Removing the stop makes it fail |
| Redirect-sanitizer origin case | Used to skip silently in both unit runs; now runs in a child process, with lookalike hosts | Runs |
| Forms CAPTCHA and Stripe integration tests | Missing `APP_SECRET` is now a failed precondition, not a pass | Changed |

### Merge order and conflicts

Merge after PR #102. The only conflicts are in `lang/fr.php` and `plugins/mcp-gateway/admin/ai-settings.php`, in both trees. The resolution, tested in `integration/final-check`:
- `fr.php`: keep both sets of keys.
- `ai-settings.php`: vet the URL first, then seal the key, and write only when both pass.

### Rollback note

Reverting leaves Forms CAPTCHA envelopes that `main` can't decrypt; affected forms fail CAPTCHA until each secret is re-entered. The rekey CLI writes nothing unless `--apply` is passed.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
