## fix(settings): settings and secrets hardening, APP_SECRET rotation, test isolation

Branch `audit/settings-hardening-next`, based on `e9a1bdf` (the existing local `audit/settings-secrets-hardening` commit). Against `origin/main`, code and tests come to 81 files, +3,011 / −460. Audit reports under `01-client/audit/` are separate documentation commits.

| Commit | Contents |
|---|---|
| `e9a1bdf` | Write-only secrets, encryption that fails closed, bounded settings, timezone ownership, brand tokens. |
| `d368b5c` | The audit follow-up that was uncommitted in the main checkout, committed **byte-identical** (tree equals snapshot `7dbc7a1a`): Forms CAPTCHA encryption, Stripe Disconnect clears both modes, payment test seam removed. |
| `b8a6fdc` | Encrypt every secret before saving a form (Backups, Booking, AI connection). Tests no longer skip silently. |
| `197fe44`, `346b48b`, `c56c7be` | `bin/rekey-secrets.php` for `APP_SECRET` rotation: envelope backup, `--restore`, refuses non-transactional tables, tested for interruption and rollback. |
| `9ac61ab`, `240caef` | Static audit check follows the encrypt-first saves; `SettingsEncryptFirstTest` restores every setting it touches. |
| `c926c18` | One definition of the licensing-plugin test helpers (fixes the licensing single-process crash). |
| `8db616f` | `APP_ENV=testing` no longer switches off the client licence guard or ModuleGuard on web requests (CLI only). |
| `5462c1e` | Central `LicenseGateTest` seeds trusted cache rows (the installation-identity trust rule), and pins that untrusted rows restrict. |
| `19c9a55`, `444c531` | Two test-order dependencies fixed (found by seeded shuffled runs). |
| `49c1fd4` | Direct web-request test of client ModuleGuard's `APP_ENV` rule (found by mutation testing). |

### Security changes

- **Encrypt first.** `slate_seal_posted_secrets()` encrypts every secret a form posts before anything is written. If any secret can't be encrypted, nothing on the form is saved and the page says so. Blank keeps the stored value; an explicit Clear removes only that secret; older plaintext values stay readable and are re-encrypted on the next save.
- **`APP_SECRET` rotation**, `OLD_APP_SECRET=… php bin/rekey-secrets.php [--apply | --restore=<backup>]`:
  - checks every `enc:v1` value in every text column of every table with an `id` key;
  - dry run by default; stops before any write if a value opens with neither secret;
  - refuses non-InnoDB tables; writes a ciphertext-only backup (mode 0600) first; one transaction, each write guarded on the value it read; any error rolls everything back;
  - rollback by a reverse run or `--restore`, which refuses unless every backed-up value opens with the configured secret;
  - never prints values; old secret only from the environment; audit-logged; safe to rerun.
- **`APP_ENV=testing`** counts only for CLI processes. A production `.env` that wrongly says "testing" no longer serves an unlicensed install.

### Tests

| Test | Result |
|---|---|
| `SettingsEncryptFirstTest` | 6/6; fails on the old code (3/6) |
| `SealPostedSecretsTest`, `ReencryptEnvelopeTest` | pass, both trees |
| `RekeySecretsCliTest` | 7/7: dry run, apply, unreadable stop, a trigger forcing a mid-run failure (nothing changes), restore (refused under the wrong secret), reverse run, MyISAM refused, corrupt ciphertext |
| `AppEnvTestingBypassTest` | 2/2, against PHP's built-in web server |
| `LicenseGateTest` (central) | 9/9 (was 5/8) |
| Shuffled order | client and licensing integration suites pass in two seeded random orders, same results as alphabetical |
| Mutations | 15/15 caught across the three PRs on the combined tree |

### Merge order and conflicts

Merge after PR #102. Conflicts only in `lang/fr.php` (keep both key sets) and `plugins/mcp-gateway/admin/ai-settings.php` (vet the URL first, then seal the key, write only when both pass), in both trees. Tested in `integration/release-check`.

### Rollback

Reverting leaves Forms CAPTCHA envelopes that `main` can't decrypt; affected forms fail CAPTCHA until each secret is re-entered. Everything else reads as before. The rekey CLI writes nothing without `--apply`. Runbook: `audit/integration-review/11-runbooks.md`.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
