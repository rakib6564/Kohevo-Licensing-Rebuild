# Slate — Phase 1 deployment

Public router + customer auth. After this, Slate has a real public-facing
surface: customers can register, verify their email, log in, reset their
password, and land on a portal dashboard. Plugins can register clean
public URLs (`/forms/<slug>`, `/book/...`) and contribute dashboard
widgets.

This bundle does NOT change any admin UI. Phase 0 should already be
deployed and verified (light sidebar, blue accent visible).

## What's in this zip

```
slate-phase1/
├── INSTALL.md                                  ← you are here
└── slate/
    ├── .htaccess                               ← UPDATED  /portal + generic router
    ├── route.php                               ← NEW      trampoline entry point
    ├── db/migrations/phase1.sql                ← NEW      4 columns, 2 indexes on `customers`
    ├── includes/
    │   ├── Auth.php                            ← EXTENDED 5 new customer methods
    │   ├── Mailer.php                          ← session-9 patch (carried forward)
    │   └── PublicRouter.php                    ← NEW
    ├── customer/
    │   ├── partials/header.php                 ← NEW      light customer shell
    │   ├── partials/footer.php                 ← NEW
    │   ├── login.php                           ← NEW
    │   ├── register.php                        ← NEW
    │   ├── verify-email.php                    ← NEW
    │   ├── forgot-password.php                 ← NEW
    │   ├── reset-password.php                  ← NEW
    │   ├── logout.php                          ← NEW
    │   ├── resend-verification.php             ← NEW
    │   └── index.php                           ← NEW      portal dashboard
    ├── templates/
    │   ├── customer-verify-email.php           ← NEW      verification email
    │   └── customer-password-reset.php         ← NEW      reset email
    └── docs/
        ├── BUILDING-PLUGINS.md                 ← UPDATED  new §8 Customer-facing plugins
        └── PLUGIN-API.md                       ← UPDATED  §6 documents public_routes filter
```

Total: 19 source files + INSTALL.md.

## Prerequisites

- **Phase 0 deployed and verified.** Customer pages inherit the new
  design system (DM Sans, blue accent, light canvas). If Phase 0 isn't
  on the server, deploy it first.
- **Database access** to run a one-time migration (`mysql` CLI, phpMyAdmin,
  or your hosting panel's DB tool).
- **SMTP configured** in Settings → Email. The customer auth flow sends
  verification emails and password-reset emails. If SMTP isn't working,
  registration succeeds but verification + reset are broken end-to-end.

## How to deploy

Back up the live site first — both the filesystem (especially `.htaccess`)
and the database.

### Step 1 — Run the database migration

The `customers` table needs 4 new columns (verify and reset token pairs)
plus 2 indexes. Run this once before deploying any file:

```sql
-- Run /slate/db/migrations/phase1.sql against your database
ALTER TABLE `customers`
    ADD COLUMN IF NOT EXISTS `verify_token` VARCHAR(64) NULL AFTER `email_verified`,
    ADD COLUMN IF NOT EXISTS `verify_token_expires_at` DATETIME NULL AFTER `verify_token`;

ALTER TABLE `customers`
    ADD COLUMN IF NOT EXISTS `reset_token` VARCHAR(64) NULL AFTER `verify_token_expires_at`,
    ADD COLUMN IF NOT EXISTS `reset_token_expires_at` DATETIME NULL AFTER `reset_token`;

ALTER TABLE `customers`
    ADD INDEX IF NOT EXISTS `idx_customers_verify_token` (`verify_token`),
    ADD INDEX IF NOT EXISTS `idx_customers_reset_token`  (`reset_token`);
```

The `IF NOT EXISTS` guards make this safe to re-run. If your MySQL is
older than 8.0 and doesn't accept `ADD INDEX IF NOT EXISTS`, drop those
two clauses and ignore "Duplicate key name" errors on a re-run.

After the migration: `DESCRIBE customers;` and confirm the four new
columns are present (verify_token, verify_token_expires_at, reset_token,
reset_token_expires_at).

### Step 2 — Deploy the files

Extract `slate-phase1.zip` over your install root so the `slate/` paths
overlay onto the live tree. Or copy file-by-file via FTP using the
contents list above as a map. Either way deploys 19 files.

### Step 3 — Reload the web server

For the `.htaccess` changes to take effect, Apache needs to re-read it.
On shared hosting this usually happens automatically on the next
request. If you have shell access, `apache2ctl graceful` or similar.

### Step 4 — Walk the verification checklist

The fastest way to confirm Phase 1 works end-to-end is to register a
test customer and walk the flow:

- [ ] Visit `https://greenlightinduction.rakibhasaan.com/slate/customer/register.php`
  → form renders with the new design.
- [ ] Fill in name + a test email you can read (e.g. a personal address)
  + password (≥ 8 chars), submit.
- [ ] You're redirected to `/customer/login.php?registered=1` with a
  "Check your email" success banner.
- [ ] The verification email arrives within a minute. The button + the
  paste-link both point to `/customer/verify-email.php?token=<64 hex chars>`.
- [ ] Click the verification link → "Your email is verified" success page.
- [ ] Click "Sign in" → land on `/customer/login.php?verified=1` →
  enter the credentials → land on `/customer/` (the portal).
- [ ] Portal shows: profile card on the left (editable name + phone),
  Account card with "Verified" badge, empty-state "No activity yet"
  on the right.
- [ ] Edit your name in the profile card, click Save → "Profile updated"
  flash.
- [ ] Click "Sign out" → back to login.
- [ ] Click "Forgot password" → enter the test email → reset email
  arrives → clicking the link opens `/customer/reset-password.php?token=...`
  with a new-password form.
- [ ] Submit a new password → success page → "Sign in" with the new
  password → back to portal.
- [ ] **Negative checks:**
  - Visit `/customer/verify-email.php?token=garbage` → error page.
  - Visit `/customer/reset-password.php?token=garbage` → error page.
  - Visit `/customer/` while logged out → redirected to login with
    `?next=` param so you land back at portal after signing in.
- [ ] **Portal alias:** `https://.../slate/portal` → loads the portal
  dashboard (rewritten to `/customer/index.php`).
- [ ] **Generic router:** `https://.../slate/forms/anything` returns
  404 (no plugin has registered `forms` yet — that's correct; the
  router is doing its job).

If any of these fail, the most likely culprits and how to triage:

| Symptom | Likely cause |
|---|---|
| Register form renders but POST does nothing / 500 | Migration didn't run. Check `customers` columns. |
| Email never arrives | SMTP not configured in Settings → Email, or `Mailer.php` not deployed. Test via Settings → Email → "Send test email" button. |
| Verification link opens "invalid or expired" immediately | Token is being saved but the link's URL doesn't match the host the customer is browsing on. Check `APP_URL` in `.env` vs the URL you're using. |
| `/portal` returns 404 | `.htaccess` didn't deploy or mod_rewrite isn't enabled. Check the file is present and not zero bytes. |
| `/forms/x` returns 500 instead of 404 | `route.php` not deployed, or PublicRouter.php missing. |
| Customer portal renders without styling | Phase 0 deployment got reverted, or Google Fonts blocked. |

## Mailer signature note

`includes/Mailer.php` in this zip uses the session-9 signature:

```php
Mailer::send(string $to, string $subject, string $bodyHtml, string $toName = '', bool $log = true): bool
```

Customer-auth email calls in Auth.php use this signature. If you have
**any other plugin** on this site that calls Mailer using the OLD
signature `($to, $toName, $subject, $body, $log)`, those will break
after this deploy.

Plugins on this site that send email:
- `shop-emails` — ships with this bundle and already uses the new signature.

If you've added any custom code or plugin not in the bundle that calls
Mailer, audit it before deploy.

## Rollback

If something goes wrong:

### Filesystem rollback
Restore the .htaccess and the includes/, customer/, templates/, docs/,
db/migrations/ directories from your pre-deploy snapshot.

### Database rollback
The migration only **added** columns and indexes. They're safe to leave
in place even after rolling back the PHP. If you want to fully revert:

```sql
ALTER TABLE `customers`
    DROP INDEX `idx_customers_verify_token`,
    DROP INDEX `idx_customers_reset_token`,
    DROP COLUMN `verify_token`,
    DROP COLUMN `verify_token_expires_at`,
    DROP COLUMN `reset_token`,
    DROP COLUMN `reset_token_expires_at`;
```

Any test customers you registered during verification will still be
in the `customers` table — they're harmless rows. Delete by hand if
you want a clean slate:

```sql
DELETE FROM customers WHERE email = 'your-test-address@example.com';
```

## Security notes

- **Reserved route prefixes.** The Apache `.htaccess` excludes `admin`,
  `customer`, `api`, `plugins`, `includes`, `uploads`, `db`, `data`,
  `docs`, `templates`, `bin`, `portal`, and `shop` from the generic
  public router. Plugin authors can pick anything else.
- **`route.php` is publicly accessible.** It lives at the install root
  and can be hit directly with `?_route=...&_path=...` query params.
  This is by design and not a privilege bypass — the dispatch logic is
  the same as for rewritten URLs. The router itself doesn't trust input;
  it only consults the `public_routes` filter and includes whatever
  plugins have registered.
- **Password reset emails always return success.** Whether or not the
  email matches an account, `/customer/forgot-password.php` shows
  "if an account exists, a link is on its way." This is intentional —
  it prevents email enumeration.
- **Tokens are 256-bit hex (64 chars).** Generated via `random_bytes(32)`.
  Verification tokens expire after 48 hours; reset tokens after 2 hours.

## What's next

Phase 1 acceptance bullets covered:

- [x] Register → verification email → click verification → account verified
- [x] Login → portal dashboard
- [x] Forgot password end-to-end
- [x] Dashboard with profile + empty activity area
- [x] Plugins can register public routes via the `public_routes` filter
- [x] BUILDING-PLUGINS.md §8 documents the customer-facing plugin API

Per the roadmap, Phase 2 is the payments-plugin generalization. That
work depends on Phase 1 being deployed and verified, which is what
this bundle delivers.
