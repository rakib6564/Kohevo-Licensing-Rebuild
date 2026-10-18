# Operational runbooks (migration, rollout, rollback)

Every step here was exercised against the test databases only. None was run against production.

## 1. Deploy the central ModuleGuard and per-plan gating (PR 2)

Before deploying, on the central server, with production `.env`:

1. Check that `.env` does **not** set `APP_ENV=testing`. After `d77b076` it no longer bypasses the guard on web requests, but it still does on CLI.
2. Run `php bin/check-tenant-plans.php`. It lists every tenant and gated module as allowed or DENIED, with the reason. It exits 0 when every active tenant is entitled, and 2 otherwise. Its only write is the `last_validated_at` stamp that every licence check makes.
3. Assign plans for each denied tenant, **starting with the operator's own tenant**. Two ways:
   - Platform › Tenants › Edit › **Plan** (platform admins only; CSRF-checked; audit-logged as `tenant.plan_assigned`);
   - `php bin/assign-tenant-plan.php <tenant-id|slug> <plan-slug|none>`, which goes through the same `TenantService::assignPlan()`.
4. Re-run step 2 until it exits 0. The Tenants list also shows a warning for active tenants that would be refused a module.

Choose plans deliberately. Professional covers Forms, Booking and Membership; Enterprise also includes `white_label` (it hides the platform mark) and `stripe-payment`. Nothing is assigned automatically.

**Recovery if the operator is locked out of a module:** platform-admin pages (Tenants, Licences, Plans) are never gated, so a platform admin can always assign a plan. If even the admin UI is unreachable, use the CLI from step 3.

**Rollback:** revert PR 2. Central Forms, Booking and Membership go back to failing with "Class ModuleGuard not found" (500). Stored plan assignments are harmless after a revert.

## 2. Rotate `APP_SECRET`

1. Back up the database.
2. Put the **new** secret in `APP_SECRET`, and keep the old one somewhere safe.
3. Run `OLD_APP_SECRET='<old>' php bin/rekey-secrets.php`. This is a dry run: it prints counts and table/column/id references, and never values. If anything is reported as UNREADABLE, stop: `OLD_APP_SECRET` is wrong or a value is corrupt.
4. Run `OLD_APP_SECRET='<old>' php bin/rekey-secrets.php --apply`. It:
   - refuses non-InnoDB tables;
   - writes `data/rekey-backup-<ts>-<rand>.json` (ciphertext only, mode 0600);
   - re-encrypts in one transaction, each write guarded on the value it read;
   - rolls everything back on any error.
5. Spot-check: open the SMTP, Stripe and Booking settings pages ("configured" badges), and send a test email.
6. Keep the backup file until you're confident, then delete it securely.

### Rollback

Either of these works; both are tested in `RekeySecretsCliTest`:

- **Reverse run:** set `APP_SECRET` back to the old secret, then run `OLD_APP_SECRET='<new>' php bin/rekey-secrets.php --apply`.
- **From the backup:** set `APP_SECRET` back to the old secret, then run `php bin/rekey-secrets.php --restore=data/rekey-backup-….json`. It refuses unless every backed-up value opens with the configured secret, and writes them back in one transaction.

Rerunning either command is safe: values already under the target secret are skipped.

## 3. Settings and secrets hardening (PR 3) and PR #102

**Migrations:** none (no schema change).

**Legacy plaintext values:** SMTP OAuth, Stripe, Forms CAPTCHA and the Multilang key are read as-is and re-encrypted the next time the form is saved. No forced re-entry is needed.

**Behaviour changes administrators may notice:**
- If `APP_SECRET` is missing, a settings form that includes a new secret saves nothing and says so. Previously it saved the other fields.
- The AI base URL must be a public https address.

**Rollback:**

| PR | What happens on revert |
|---|---|
| PR #102 | Envelopes stay readable. |
| PR 3 | Forms CAPTCHA envelopes can't be decrypted by the old code; affected forms fail CAPTCHA until each secret is re-entered. Everything else reads as before. |

## 4. Live integrations

Stripe, Twilio, Google OAuth, Microsoft OAuth, AI and translation providers were **not** called by any test. Verify them in each provider's sandbox before release:

| Integration | What to check |
|---|---|
| Stripe | Test keys: save → configured badge; test charge; webhook delivery; Disconnect clears both modes |
| Google (SMTP OAuth, Booking calendar, Backups Drive) | A Google Cloud test project with an OAuth consent screen in testing mode: connect → token stored encrypted (`enc:v1:` in `settings`/tables) → refresh after expiry |
| Twilio | Test credentials: send to the magic test number |
| AI provider | A public https endpoint (OpenAI-compatible) with a test key: chat from the admin assistant; then point the base URL at a private IP and confirm it is refused on save |
| Translation | LibreTranslate demo or a self-hosted instance: save the key → auto-translate one string |
