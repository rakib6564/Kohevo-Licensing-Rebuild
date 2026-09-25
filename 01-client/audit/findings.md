# Slate platform — code audit findings

Read-only audit. Tree: `claude/slate-platform-audit-c95b8f` @ c3a90ba (= `develop` + the Phase C/D content-spine commits). Supporting detail in `audit/inventory.md` and `audit/raw/*.md`.

Every claim cites `file:line`. Findings are marked `verified` or `unverified`; two are marked verified-as-defect with exploitability explicitly bounded.

---

## Summary — the six that matter most

1. **Seven maintenance scripts across two plugins had no authentication and no deny rule covering them** — three destructive, all bypassing the app's bootstrap. Host check confirms `register_argc_argv` is On, so the destructive `?--apply` path was live-capable; access logs show it was never reached. Latent exposure, not an incident. Fixed on `fix/sec-1-deny-tools-dirs`, not yet deployed.
2. **The next `rsync --delete` deploy destroys 71 live files**, including a 20-file plugin that exists in no branch — production has drifted off every git ref and the workflow is armed to "correct" it (H-0b).
3. **`APP_SECRET` defaults to empty and three HMAC call sites guard it with `defined()`, which is always true** — so shop's storefront CSRF token and forms' document-download token are derivable by anyone. The codebase gets this right in four other places.
4. **Member medical data has no deletion path anywhere** — core has no customer-erasure route and no `customer_deleted` hook, so allergies, pathologies and body photographs cannot be removed through the product.
5. **Two public lookups omit the tenant filter that their sibling copies have** — booking-plus's `manage_token` and react-site-bridge's `public_id`. The RSB one needs no secret: the identifier is in the site's own URL.
6. **Booking reminders compare a PHP-clock timestamp against the MySQL clock** — the exact mistake `slate_db_now()` was written to prevent, and which the codebase records as having already broken the login throttle and membership expiry. Membership's dashboard is separately wrong in three coupled ways (H-5).

---

## Critical

None exploited. H-0 had the capability — the host check confirmed `register_argc_argv` is On, so an anonymous `?--apply` request would have deleted studio enrolment, fee and attendance rows. It stays at high rather than critical on the strength of the log evidence: zero requests to those paths across the full window the scripts have existed, so the exposure was never reached. Had a single hit appeared, this would be a critical and an incident response rather than a fix.

H-0b is arguably the more urgent item now: it is not a vulnerability but it is armed, and the loss is certain rather than conditional the moment someone dispatches a deploy.

---

## High

### H-0 · Seven maintenance scripts have no authentication and no deny rule covering them; three are destructive

`plugins/studio/tools/` holds `apply_companyb_policies.php`, `cleanup_companyb.php`, `purge_studio_orphans.php`, `raise_season_fees.php`, `seed_companyb.php`, `send_fee_reminders.php`. **None** has a `PHP_SAPI` CLI guard, a `CRON_SECRET` check, or any `Auth::` call.

They bypass the application bootstrap entirely — instead of requiring `config.php` (which starts a session and loads `Auth`), each hand-rolls credential loading, `plugins/studio/tools/purge_studio_orphans.php:30-38`:

```php
foreach (file($ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    …
    if (str_starts_with($k, 'DB_')) define($k, $v);
```

so they run with database credentials but no session, no permission check and no audit trail. The destructive path is gated only by a command-line flag — `:41` `$APPLY = in_array('--apply', $argv, true);`

Nothing in `.htaccess` protects them: the root config denies `_*.php` (`:33-35`), dotfiles (`:37`), logs and backups (`:52`) and five directories (`:57-61`) — `plugins/*/tools/` is in none. The rewrite rules serve real files directly (`:84-85`).

The `.htaccess` comment at `:29-32` records that this exact class of bug was already found and fixed here once: *"Developer scratch scripts live at the document root as _name.php. They were reachable over HTTP with no authentication: one dumped form submissions with submitter email addresses, others rewrote menus, changed settings, or wrote to files on disk."* That fix was scoped to the root naming convention and never generalised to `tools/`.

**Failure case, unconditional if reachable.** `GET /slate/plugins/studio/tools/purge_studio_orphans.php` executes the script's dry-run path, which prints a full orphan report — per-table row counts and family ids — to an anonymous caller. The other dry-run outputs disclose fee and season data the same way. "Unconditional" here means it needs no flag and no `register_argc_argv`; it still assumes the request reaches PHP, which is the inference recorded below.

**Failure case, conditional.** With `register_argc_argv = On` under a web SAPI, PHP populates `$argv` from the query string split on `+`. `?--apply` then sets `$APPLY = true` and the deletions execute against `studio_costumes`, `studio_fees`, `studio_attendance`, `studio_enrollments`, `studio_family_members` and `studio_contact_roles`; `raise_season_fees.php?--apply` mutates fee amounts; `send_fee_reminders.php?--send` mails every family with a balance. With the directive Off, `$argv` is undefined and PHP 8 raises a TypeError before the destructive branch — a 500, not data loss.

**Fix.** Contain immediately with an `.htaccess` deny for `plugins/*/tools/` — better, for any path segment named `tools`, `bin` or `scripts` at any depth, matching how the existing `FilesMatch` rules already apply depth-independently. Durably, each script should refuse to run outside the CLI as its first statement, as core already does at `config.php:95` and `:121`. A maintenance script reachable by HTTP request has an authentication requirement it does not have. Sweep `plugins/shop/bin/` and the root `bin/` for the same shape.

**Scope correction — one more directory.** As first written this finding covered `plugins/studio/tools/` only. A follow-up enumeration of every directory named `tools`, `bin` or `scripts` in the tree found a second unprotected one: **`plugins/shop/bin/migrate-images.php`** — same shape, no `.htaccess`, no CLI guard. The other four such directories are already protected by their own `Require all denied` file (`bin/.htaccess`, `tests/.htaccess`, and see L-5). So the exposed set is `plugins/studio/tools/` (six scripts) plus `plugins/shop/bin/` (one), and the pattern is "a plugin added a script directory and no per-directory deny came with it" — which is what makes a class-wide rule the right remedy rather than two more `.htaccess` files.

**Evidentiary status — resolved on the host, 2026-09-01.** The code half was read from source; the deployment half has since been checked by the operator on the live server, and both open conditions are now closed:

- **`register_argc_argv` is `On`.** The `?--apply` path was therefore **live-capable**, not merely theoretical — the destructive branch would have executed on an anonymous GET carrying that query string.
- **Access logs from 31 Jul 2026 → 1 Sep 2026, plus same-day, show zero requests to `plugins/*/{tools,bin,scripts}/`.** No enumeration, no probe, no hit of any kind against the exposed paths.
- **`_*.php` and `.env` probe attempts in the same window all returned 403**, confirming the existing deny rules work and that the site is being scanned — the exposed directories simply were not in any scanner's wordlist.

**Conclusion: latent exposure confirmed, no evidence of exploitation, no credential rotation required.** This is a fix, not an incident response. Recording the negative explicitly because "we looked and found nothing" is a different and much stronger statement than "we did not look" — and because the log window covers the whole period the scripts have been present.

**verified** on both halves: unauthenticated and unguarded in source, reachable and destruction-capable on the host, never actually reached.

**Remediation status.** Fixed on branch `fix/sec-1-deny-tools-dirs` (commit `e8cfc8a`): a class-wide `RewriteRule (^|/)(tools|bin|scripts)/ - [F,L]` in the root `.htaccess`, placed before the file-serving rules and matched relative to the install directory so it holds under `/slate/`. **Not yet live** — `.github/workflows/deploy.yml:5` is `workflow_dispatch` only ("nothing here fires on a push"), so the exposure window stays open until that workflow is dispatched against a ref containing the commit.

### H-0b · Production has drifted off every git ref, and the deploy workflow's `rsync --delete` would destroy 71 live files — including an entire plugin that exists in no branch

Found while establishing the release scope for the H-0 fix, and it blocks that fix from shipping the obvious way.

**Production is not any commit.** The live tree at the deploy path is a git checkout whose HEAD is `f5d712f` (Merge PR #8) — but its working tree carries **63 modified tracked files and 37 untracked files**. Comparing key files against three candidate refs:

| ref | key files differing (of 5 sampled) |
|---|---|
| `f5d712f` (live HEAD) | 5 |
| `develop` | 5 |
| `c3a90ba` (audit branch) | 3 |

It matches none of them. Production is an accumulation of rsync deploys from assorted feature branches on top of a stale checkout.

**`--delete` is unconditional in the workflow** (`.github/workflows/deploy.yml:87`, `rsync -az --delete`). CI deploys a clean checkout of the dispatched ref, so anything live that is not tracked in that ref and not excluded is removed. Against `develop` that is **71 files**:

- **`plugins/multilang-translate/` — 20 files, 200 KB — exists on NO branch and is not gitignored.** `git log --all -- plugins/multilang-translate` returns nothing; it was deployed and never committed. Its `plugin.json` was modified 31 Aug 2026, so it is live and maintained. A deploy deletes the plugin outright. (This also corrects L-4, which recorded the plugin as non-existent on the strength of the repository alone.)
- **Six Phase C/D content-spine files that `develop` does not have** — `content-builder/public/api.php`, `lib/MediaKeyResolver.php`, `lib/PrecomposedBody.php`, `lib/SiteTemplate.php`, `lib/blocks/react.php`, `public/assets/slate-content.js`. Live `ContentBuilderAPI.php` is also modified and references them, so deleting these while reverting that file is a fatal-error combination, not a graceful downgrade.
- 3 `docs/` files, ~60 `tests/` fixtures and integration tests (functionally harmless), and `.ftpquota` (a cPanel artefact, regenerated).

**And 63 tracked files would be reverted**, including `plugins/booking/BookingAPI.php`, `plugins/booking/public/router.php`, `plugins/content-builder/ContentBuilderAPI.php`, `admin/assets/builder.js` and `admin/site.php`. Since production carries Phase C/D work that `develop` predates, deploying `develop` is a **partial rollback of live code**, not a fast-forward.

**Failure case.** An operator dispatches Deploy against `develop` to ship a one-line `.htaccess` fix. The site loses its translation plugin, loses six content-spine files that surviving code calls into, and has sixty-three files rolled back to older versions. The post-deploy smoke test (`tests/smoke.php`, `deploy.yml:117`) is unlikely to catch a missing plugin or a reverted renderer.

**Blast radius.** The next deploy from any ref, by anyone. This is the highest-likelihood data-loss path in the system, and it is armed right now.

**Fix.** Three things, in order. First, **do not dispatch a full deploy to ship the `.htaccess` fix** — see the recommendation below. Second, get production back under source control before any deploy: commit `plugins/multilang-translate/` to a branch, and reconcile the Phase C/D files (they exist on `c3a90ba`, so merging that lineage into `develop` is likely the real answer). Third, run the workflow with `dry_run: true` — it already supports `--dry-run --itemize-changes` (`deploy.yml:89`) — and read the deletion list before every real deploy, until the drift is closed. Longer term, deploying with `--delete` from a tree that has never matched the target is a foot-gun regardless of who is careful; either the untracked-but-required files get committed, or they get added to the exclude list so their presence is deliberate rather than accidental.

**verified** — file-list comparison against the live tree, read-only

### H-1 · `APP_SECRET` is guarded by `defined()` rather than emptiness, so unset means "sign with the empty string"

`config.php:49` always defines the constant, empty by default:

```php
define('APP_SECRET', env('APP_SECRET', ''));
```

Every `defined()`-only guard therefore resolves to `''` and the fallback literal is dead code. Three sites:

- `plugins/shop/storefront/includes/layout.php:127` — storefront CSRF, **no guard at all**, under a docblock at `:120` asserting it "Is unguessable without APP_SECRET":
  ```php
  return hash_hmac('sha256', 'shop-csrf:' . $sid, APP_SECRET);
  ```
- `plugins/forms/public/router.php:700` — the public "Save PDF" token
- `plugins/forms/lib/FormsSpamGuard.php:497` — the anti-spam time-trap signature

The correct test exists four times over: `includes/helpers.php:218-219` and `:232` refuse on `APP_SECRET === ''`; `plugins/forms/FormsAPI.php:2462-2463` skips webhook signing when empty; `admin/settings.php:269` blocks OAuth setup. `admin/settings.php:959`, `:1720`, `:2004` even surface the constant's status in the UI.

**Failure case.** With `APP_SECRET` empty, `sf_csrf_token()` is `hash_hmac('sha256','shop-csrf:'.$sid,'')` — computable by anyone, so `sf_csrf_verify()` (`layout.php:135-139`) accepts forged requests and storefront CSRF protection is absent, not weakened, on every state-changing storefront POST. For forms, an attacker computes the PDF token offline for any `$ref`; the only barrier left is the ref, and `FormsAPI::generateRef():2303-2305` yields `'SUB-' . bin2hex(random_bytes(4))` — **32 bits**. Enumerating that against `?action=pdf&ref=…&t=…` with self-computed tokens returns other people's submissions, which on an e-signature form is a signed document with names, addresses and a signature image.

**Blast radius.** Any deployment with `APP_SECRET` unset — or left at the `.env.example:17` placeholder `change-me-to-64-random-hex-chars`, which is non-empty but published in this repo and therefore equally known.

**Fix.** The three sites should test `APP_SECRET !== ''` and, more importantly, refuse to issue or accept a token when the key is absent rather than degrading to a known one. A single keyed-token helper that throws on an unconfigured secret — mirroring `slate_encrypt_secret()` — would make the failure loud at every consumer instead of leaving each to remember. `generateRef()`'s 32 bits is separately thin for a value gating document access and deserves widening.

**verified** as a code defect at all three sites. **Exploitability is conditional** on the deployed `APP_SECRET`, which I could not read — `.env` is gitignored and absent from this worktree. The APP_SECRET panel at `admin/settings.php:1710-1720` settles it immediately.

---

### H-2 · Member medical and health data has no deletion path anywhere in the platform

Special-category data is stored across two plugins: `membership_profiles.medical_notes`, `allergies`, `dob`, `gender`, `emergency_*` (`plugins/membership/install.sql`), and `coaching_profile.pathologies`, `ongoing_care`, `alternative_medicine`, `personal_issues`, `intolerances`, `height_cm`, `weight_kg` (`plugins/coaching/install.sql`), plus client body photographs at `coaching_diary_photo.file_path` and `coaching_message.photo_path`.

Neither plugin has an erasure routine. Coaching's deletes (`CoachingAPI.php:386`, `:440`, `:511`, `:764`, `:827`, `:910`, `:994`) are all per-item. Membership's only delete is for plans (`admin/plans.php:86-107`).

Underneath that, **core has no customer-deletion path at all**: no customers admin screen, no `DELETE FROM customers` anywhere in the tree, and no `customer_deleted` action for plugins to subscribe to — `src/Services/Auth/Auth.php` fires `customer_registered` (`:690`), `customer_logged_in` (`:595`) and `customer_email_verified` (`:744`), and nothing for deletion. `admin/users.php:183-188` deletes *admin users* only.

**Failure case.** A member exercises their right to erasure. No screen, API or CLI path removes their record. Uninstalling the plugin is not a workaround: `uninstall.sql` drops tables but cannot remove avatar and diary-photo files already written under `uploads/`, which then persist with no row left to locate them by. Booking ships `deleteCustomerData()` (`plugins/booking/BookingAPI.php:1387`) but it is keyed by email, scoped to booking's own tables, and wired to nothing.

**Blast radius.** Every tenant holding member health data — the live tenant does. GDPR Article 9 data with no Article 17 route.

**Fix.** This needs a core capability, not plugin patches. Core should own a customer-erasure entry point that fires an action plugins subscribe to, so each removes its own rows and files in one auditable operation; booking's existing routine becomes the first subscriber. Erasure must reach the filesystem, since uploads outlive any table drop. Build the export path at the same time — the same fan-out serves Article 15, and booking already shows the shape (`exportCustomerData():1378`).

**verified**

---

### H-3 · Two public lookups omit the tenant filter their sibling copies have

**(a) `plugins/booking-plus/public/message.php:32-40`** resolves a booking by `manage_token` with no tenant predicate:

```sql
WHERE a.manage_token = ?
  AND a.starts_at >= NOW() - INTERVAL 7 DAY
```

Four sibling copies of the same lookup do scope: `booking/admin/invoice.php:20`, `booking/public/router.php:1127`, `:1238`, `booking/public/pay-intent.php:65`.

*Failure:* a tenant-B customer opens the link on tenant A's host. The page renders B's name, service, provider and time. On POST, `saveAppointmentMeta()` (`BookingPlusAPI.php:126-146`) finds no row for `(tenant A, B's appointment)` and **inserts one stamped `tenant_id = A`**; `bookingplus_notify_therapist()` mails it to A's admin (`BookingPlus.php:398`). B's therapist never receives it and B's nudge cron cannot see it (`BookingPlus.php:384-395`). Requires possession of a valid 32-hex token.

**(b) `plugins/react-site-bridge/ReactSiteBridgeAPI.php:53-59`** resolves a site by `public_id` with no tenant predicate, while both siblings scope (`:48`, `:565`). Reached from `public/host.php:19` via `:554`.

*Failure:* the `public_id` appears in the URL of every published site, so it is **not a secret**. Requesting `https://tenant-a/react-sites/<tenant-B-public-id>/` serves B's entire published site — every route and asset — under A's origin. No credential required, which makes this the more exploitable of the two.

**Blast radius.** Bounded by the tenancy model: `current_tenant_id()` (`includes/helpers.php:19-31`) and `TenantContext::id()` (`src/Tenancy/TenantContext.php:45-54`) resolve to the `.env` `TENANT_ID` constant, and the `tenants` table has no host column — so **one deployment serves one tenant**. These are real holes in the shared-database isolation model, live where two deployments share a database, and inert on a single-tenant install.

**Fix.** Add the missing filters. The durable point is that both defects are one copy out of several of the same lookup — consolidating each behind a single scoped accessor (`findByManageToken()`, one site resolver) removes the chance of the next copy drifting. Also tighten `message.php:22`'s token regex (`/^[a-zA-Z0-9]{16,64}$/`) to the `/^[a-f0-9]{32}$/` the issuer actually produces.

**verified**

---

### H-4 · Booking reminders and follow-ups compare a PHP-clock timestamp against the MySQL clock

`starts_at`/`ends_at` are written from PHP — `plugins/booking/BookingAPI.php:489-490`:

```php
$endsAt      = date('Y-m-d H:i:s', $endTs);
$startsAtSql = date('Y-m-d H:i:s', $startTs);
```

and compared against the database clock — `plugins/booking/Booking.php:326-327`:

```sql
AND a.starts_at > NOW()
AND a.starts_at <= NOW() + INTERVAL ? MINUTE
```

Same shape at `Booking.php:349-350` (follow-ups), `:151`, `:155`, and `admin/index.php:24`, `:35-36`. `includes/helpers.php:200-207` states the platform's own position: *"on this host PHP runs UTC and MySQL runs SYSTEM, a four-hour gap… It disabled the login throttle outright and handed every membership four hours of unpaid access before anyone noticed."* `slate_db_now()` is the remedy and booking calls it **zero** times.

**Failure case.** A 09:00 booking stores the literal wall clock `'…09:00:00'` in PHP's zone. The 60-minute lead selects between MySQL's `NOW()` and `NOW()+60min`. With MySQL four hours behind, at real 08:00 the window is 04:00–05:00 and the row is missed; it only enters the window once MySQL reaches 08:00 — real 12:00, after the session started. "Today's appointments" (`DATE(a.starts_at) = CURDATE()`) is wrong for a four-hour band around midnight.

Same defect, smaller stakes, in booking-plus's nudge SLA: `therapist_notified_at` written by PHP (`public/message.php:63,67`) and compared at `BookingPlus.php:394` against `NOW() - INTERVAL ? HOUR`, so the "within 8 hours" promised at `message.php:70` is enforced as 12 (or 4). Also `cancelled_at` (`BookingAPI.php:710`), `nudge_sent_at` (`BookingPlus.php:414`), `therapist_replied_at` (`booking-plus/admin/index.php:28`), and `membership_profiles.consent_at` (`membership/public/router.php:95`).

**Blast radius.** Every reminder and follow-up on every tenant running booking; the dashboard KPI widget and index counts. Dormant only where the two clocks happen to agree.

**Fix.** Pick one clock for both write and compare. Writing these columns through the database clock is the smaller change and leaves the many `NOW()` comparisons untouched. Either way, existing rows were written under the old convention, so a fix shifts the meaning of every historical `starts_at` by the offset and needs a data pass, not just a code change.

**verified** — the mismatch is structural and provable from the code; the four-hour magnitude is the codebase's own assertion at `helpers.php:202-203`, not something I measured on the live host.

---

### H-5 · Membership's dashboard misreports attendance, enrolment and quota — three coupled defects

**(a) Attendance counts sessions that have not happened.** `plugins/membership/public/views/home.php:87`:

```php
$present = in_array($a['status'], ['confirmed','completed'], true);
```

`MembershipAPI::recentAttendance():752-763` applies **no time filter** and orders `starts_at DESC`, so the furthest-future booking sits at the top of "Recent attendance". `confirmed` is assigned at creation (`BookingAPI.php:530`) — a scheduling state, not an outcome. The same unbounded predicate drives `sessionStats()['total']` (`:742`), `['month']` (`:744`), `sessionBreakdown()` (`:771`) and `weeklyActivity()` (`:786`). A member with six future bookings and zero attended sessions reads "6 attended", and next Friday's class shows "✓ Present" with next Friday's start time as the arrival time.

*Should key off:* the session having ended. `completed` is never set automatically — `changeStatus()` is called only from `booking/admin/appointment.php:65`, a manual action — so a status-only fix under-reports.

**(b) "Enrolled" is shown for every active service.** `MembershipAPI.php:801` lists every active service with no member predicate; `:817` sets `locked` from insurance alone; `home.php:257` renders "✓ Enrolled" as the `else` of `locked`. Seven services the member never booked read "✓ Enrolled · 0 sessions" above a zero-width bar. `used` is computed one line away (`:811-814`) and never consulted. Root cause: **no enrolment concept exists in the schema** — the UI asserts a state the model cannot express. `LIMIT 12` also silently truncates the catalogue.

**(c) `session_quota` is sold but never enforced.** Editable at `admin/plans.php:69` with the hint "Number of sessions this plan includes" (`:255`); read only for display (`views/plans.php:51`, `views/home.php:21`). The booking gate `Membership::canBook()` (`Membership.php:107-140`) tests active membership, profile completion and insurance — never quota. A "10-session pass" grants unlimited bookings for `duration_days`; the dashboard prints "11 / 10 sessions" while the booking still succeeds.

**Blast radius.** Every member on every tenant running membership + booking. (c) is direct revenue loss.

**Fix.** These share one root: there is no single definition of "an attended session". Define it once — sessions whose `ends_at` has passed — and have the five aggregate/list callers, the enrolment badge and the quota check all read it. **Sequence matters**: enforcing quota before fixing the attendance predicate would block members using a count that includes future bookings, which is worse than not enforcing it. Enrolment needs either derivation from `used`/the active plan's `course_id`, or an explicit enrolment record.

**verified**

---

## Medium

### M-0 · *(downgraded — see L-5)*

Originally filed at medium on the claim that the operator's intent to fence off five directories "is not in force anywhere." **That claim was wrong.** Retained as L-5 with the correction. Identifier kept here so existing references resolve.

### M-1 · The storefront uses a second, weaker CSRF implementation than the rest of the platform

Core provides session-based `csrf_verify()` (`includes/helpers.php:54-76`), which restaurant's storefront uses correctly on all four state-changing entry points (`storefront/checkout.php:29`, `cart.php:7`, `item.php:18`, `api.php:40`). Shop's storefront instead derives its own HMAC token (`shop/storefront/includes/layout.php:127-139`) — the H-1 site. The stated reason is legitimate (the storefront serves guests with no PHP session), but restaurant demonstrates the core helper works there. Two implementations of one primitive, and the bespoke one is the broken one.

*Fix.* Have both share one keyed-token helper that fails closed on an unconfigured secret, rather than maintaining two schemes with different failure modes.

**verified**

### M-2 · Changing the shop currency setting re-denominates the whole catalogue without changing a price

`shop_products` has no currency column; eleven sites read one global `Database::setting('shop.currency') ?: 'USD'` (`ShopAPI.php:111`, `:499`, `Shop.php:142`, `:220`, `admin/products.php:24`, `admin/index.php:18`, `admin/reports.php:17`, `admin/customers.php:71`, …). Only the order snapshots it (`shop/install.sql:76`). Switching USD→EUR leaves every number unchanged and every label different, and `create-intent.php` charges the new currency. Restaurant has no currency column at all; booking gets it right per-service (`booking_services.currency`).

*Fix.* Price and currency should travel together, as booking already does. Interim: make the settings screen state that this re-denominates rather than converts, and confirm.

**verified**

### M-3 · Any path under `/member` renders the Home dashboard with HTTP 200 instead of 404

`plugins/membership/public/router.php:34` reads only `$_GET['view']`; `:244-246` falls through to `home` for anything unrecognised. The router never reads `_route_path`, which `src/Kernel/Http/PublicRouter.php:100` sets and booking consumes (`booking/public/router.php:39`). `/slate/member/profile` therefore renders Home with 200 — as does any typo. In-app nav hides this because the tabs emit `?view=` links (`router.php:231`).

*Fix.* Resolve from `_route_path` first, fall back to `?view=`, and 404 an unrecognised view. Worth checking every plugin registering a public route — the contract is established but followed unevenly.

**verified**

### M-4 · `booking.reminder_leads` has three writers and Booking+ reseeds it on every request

`booking/admin/settings.php:30` (coercing empty input to `'1440,60'`), `booking-plus/admin/settings.php:28`, and `booking-plus/BookingPlus.php:56-61` called from `boot()` (`:24`). The boot seed overwrites whenever the value is empty **or exactly `'1440,60'`**, so an operator who deliberately chooses the documented default has it replaced by Booking+'s cadence on the next page load. `'1440,60'` is unsettable while Booking+ is active. Deterministic, but the opposite of what two settings screens imply.

*Fix.* The seed needs a one-shot marker rather than inferring "untouched" from the value. One key should have one editor.

**verified**

### M-5 · Booking's `uninstall.sql` drops 5 of its 16 tables, stranding customer PII

Dropped: `booking_appointments`, `booking_provider_hours`, `booking_provider_services`, `booking_providers`, `booking_services`. Left behind: `booking_customers` (name, email, phone, history), `booking_categories`, `booking_locations`, `booking_resources`, `booking_service_resources`, `booking_service_addons`, `booking_custom_fields`, `booking_provider_breaks`, `booking_date_overrides`, `booking_coupons`, `booking_gift_cards`. booking-plus drops all three of its own correctly.

Uninstalling — plausibly *to* remove customer data — leaves it with no UI able to read or delete it, since the only erasure routine shipped with the removed plugin. Reinstalling silently inherits the previous era's customers, coupon counts and gift-card balances.

*Fix.* Drop every table the plugin creates across `install.sql` and all four migrations. The list was written against the original five-table schema and no migration since has updated it, so deriving it from the installer's manifest is safer than maintaining it by hand.

**verified**

### M-6 · Zoom `api` mode is selectable and persisted but has no implementation

Offered at `booking-plus/admin/service.php:238` (`'api' => 'API — real Zoom OAuth (Phase 1.5, not wired)'`), accepted by the validator at `BookingPlusAPI.php:86-87`, but the only consumer handles two modes (`BookingPlus.php:314-317`). No Zoom client, API call or writer of `zoom_join_url` exists. Selecting it leaves `$zoom` empty, skips the `fallback_message` branch, and substitutes an empty `{{zoom_url}}` into every confirmation and reminder — clients get a "here is your link" email with no link and no fallback. `admin/services.php:75-76` displays it as a configured mode alongside working ones.

*Fix.* Remove it from the select and reject the value until the integration exists, or fall through to `fallback_message` at render. The "not wired" label is insufficient when the value saves and the failure is silent and client-facing.

**verified**

### M-7 · Deleting a form submission leaves its uploaded files and signatures on disk

All three paths delete only the row: `forms/admin/submission.php:95`, `admin/submissions.php:180`, `admin/index.php:28` (whole form). Files are written to `uploads/forms/` by `FormsAPI.php:2352`. Nothing calls `Uploads::remove()`. Coaching does clean up (`CoachingAPI.php:451`), so the pattern exists.

The UI confirms "This cannot be undone" (`admin/submissions.php:509`) while the identity document or signature survives, unreferenced and still served. Deleting a form orphans every file every submitter ever uploaded to it.

*Fix.* Remove files before the row, in one shared `FormsAPI::deleteSubmission()` the three screens call. Sweep for already-orphaned files afterwards.

**verified**

### M-8 · Date of birth, gender and medical fields are collected twice with incompatible types

`membership_profiles` and `coaching_profile` independently store DOB (both `DATE`), gender (`NOT NULL DEFAULT 'undisclosed'` vs `NULL`, so "not asked" and "declined" are distinguishable in one and conflated in the other), allergies (`TEXT` vs `intolerances JSON`), medical notes (one `TEXT` vs a four-way split), and emergency contact (three columns vs `therapist_contact JSON`). Both are written from their own forms; nothing reads across.

A member updates an allergy in one place only; a coach and a receptionist then see different allergy information for the same person, with no authoritative record and no detection.

*Fix.* These belong to the person, not the plugin — the identity spine exists for this and is used only by `studio`. That is a migration: choose a canonical representation per field (structured for allergies), backfill both, resolve conflicts with a human in the loop. Minimum interim: designate one table authoritative per field and have the other read through.

**verified**

### M-9 · The Twilio auth token is stored in cleartext while comparable secrets are encrypted

`booking/admin/settings.php:47-48` writes it raw; `BookingAPI.php:1580` reads it raw. `slate_encrypt_secret()` is used for equivalent secrets at `includes/SmtpOAuth.php:96`, `admin/oauth_callback.php:74-75`, `admin/settings.php:139` and `plugins/sitehub/SitehubAPI.php:33`. The form renders it as `type="password"` with a masked placeholder (`:157`), so confidentiality is clearly intended. Any read of the `settings` table — a backup, a reporting connection, `admin/repair-settings.php` — yields a live credential that can send messages billed to the tenant.

*Fix.* Wrap in `slate_encrypt_secret()`/`slate_decrypt_secret()` as `admin/settings.php:136-139` already does for SMTP, including its handling of an unset `APP_SECRET`. Legacy cleartext values lack the `enc:v1:` prefix and pass through `slate_decrypt_secret()` unchanged (`helpers.php:231`), so reads keep working during migration. Contingent on H-1 being fixed first.

**verified**

---

## Low

### L-1 · Plugin migrations are forward-only with no rollback

`booking/migrations/{0.2.0,0.4.0,0.5.0,0.5.1}.sql`, `shop/migrations/1.1.0.sql`, `clientdesk/migrations/{2.0.0,2.1.0}.sql`, `timeclock/migrations/1.0.0.sql` are plain SQL with no `down()` counterpart, unlike core's PHP migrations (`db/migrations/0002_identity_core.php:97-105`). Whether the plugin runner is *meant* to support rollback is a core design question (`src/Data/MigrationRunner.php`, `src/Kernel/Module/PluginLoader.php`) rather than a per-plugin defect, which is why this is low. **verified**

### L-2 · The content API resolves any nested route to its last path segment

`ContentBuilderAPI.php:723-729` discards everything before the final `/`, so `GET /api/content/anything/at/all/home` returns Home with 200 and an `address.route` of `/home`. Unknown slugs do correctly 404 (`:730`) and only published posts are returned, so the impact is duplicate cache entries and a route that disagrees with the API's own echoed address. New on this branch only. Deciding now whether routes are hierarchical is cheaper than changing the contract later. **verified**

### L-3 · Four join tables carry no `tenant_id`

`booking_provider_services`, `booking_service_resources`, `contentbuilder_post_meta`, `contentbuilder_term_relations`. **I could not construct a failure** for any of them: all are keyed on ids that are globally unique across tenants, and every read path reaches them via a parent already resolved with a tenant filter. The boundary holds transitively but is not stated in the schema, so a future query reaching them without resolving the parent first would have none. Defence in depth; schedule, do not hotfix. **verified as not currently exploitable**

### L-4 · `multilang-translate` is absent from the repository *(corrected — it exists on production)*

**Original claim, now corrected.** I recorded this as "the plugin does not exist," on the strength of `plugins/` and a tree-wide grep. That was true of the repository and **false of the running system**.

`plugins/multilang-translate/` is present on the live server — 20 files, 200 KB, `plugin.json` last modified 31 Aug 2026 — and `git log --all -- plugins/multilang-translate` returns nothing, so it exists on no branch and is not gitignored. It was deployed and never committed.

The brief was right and the code was wrong, which inverts the "where the code contradicts the browser audit, the code wins" rule I applied: the *repository* is not the system of record for what is deployed. Worth remembering for the rest of this report — every "does not exist" conclusion drawn from the tree alone carries the same caveat.

The consequence is not documentation drift but data loss: the next `rsync --delete` deploy removes the plugin. Escalated into **H-0b**, which is where the remediation lives. Left at low here because the finding as originally scoped — repo and deployment disagree — is itself accurate and minor; the severity is in H-0b.

**verified** (both the absence from the repo and the presence on the host)

### L-5 · Five `.htaccess` directory denials never match under this install's sub-path *(was M-0)*

**Original claim, retained.** `.htaccess:57-61` denies five directories with `RedirectMatch 403 ^/includes/`, `^/db/`, `^/scripts/`, `^/docs/`, `^/data/`. `RedirectMatch` matches the URL path from the **domain root**, and the app is not there — `:81` sets `RewriteBase /slate/` and `:19-21` point `ErrorDocument` at `/slate/403.php`. Real request paths are `/slate/includes/…`, which `^/includes/` does not match. All five denials are inert. That much is still true and still verified.

**Correction — the severity was wrong.** I wrote that "the operator's stated intent is not in force anywhere." It is. Every one of those directories carries its own `.htaccess` with `Require all denied`, and two of them name this exact problem:

- `db/.htaccess` — *"Schema/migration SQL — never web-served. (Subdirectory-safe block; the root RedirectMatch rules don't fire under a /subdir/ deployment.)"*
- `includes/.htaccess` — *"Core PHP classes — never directly web-served. (Subdirectory-safe block.)"*
- `docs/.htaccess`, `src/.htaccess`, `tests/.htaccess`, `vendor/.htaccess`, `bin/.htaccess` — same pattern.

So the team already found this, already understood the sub-path cause, and already compensated with a mechanism that works. The `RedirectMatch` lines are dead code, not an open hole. `scripts/` and `data/` have no directory of their own in the tree at all — `scripts/` does not exist, and `data/` is created at runtime for the log, which is separately denied by the depth-independent `FilesMatch` at `:52`.

**Residual issue, now low.** Dead rules that look protective invite someone to trust them — the next directory added under the same assumption gets no protection, which is precisely how `plugins/studio/tools/` slipped through (H-0). Worth deleting or repairing so the file states what is actually true.

*Fix.* Either delete the five `RedirectMatch` lines as superseded by the per-directory files, or re-anchor them relative to the install. Deleting is honest and lower-risk; repairing gives belt-and-braces. Either way the comment at `:56` should stop claiming a block that does not happen.

**verified** — both the original mechanism claim and the correction

---

## Cross-cutting patterns

**1. One access path, written N times, one copy drifts.** This is the single most productive root cause found, and it reframes the browser audit's thesis. The problem is less that plugins duplicate *objects* than that they duplicate *access paths* and the copies diverge:

- five `manage_token` lookups, one missing the tenant filter (H-3a)
- three `public_id`/site lookups, one missing it (H-3b)
- seven `APP_SECRET` consumers, four correct and three wrong (H-1)
- five copies of the attendance predicate, all sharing one wrong definition (H-5a)
- three slug generators, three currency formatters, two CSRF implementations, two template renderers

The fix shape is the same each time: one accessor that enforces the invariant internally, so the next copy cannot omit it.

**2. Time is read from two clocks.** PHP writes, MySQL compares. Confirmed across booking, booking-plus and membership (H-4). `slate_db_now()` exists specifically for this and is used by none of them. The docblock at `helpers.php:193-213` records two prior production incidents from the same cause.

**3. Deletion stops at the row.** No core customer-erasure path and no `customer_deleted` hook (H-2); forms orphans uploaded files (M-7); booking's uninstall strands eleven tables (M-5); no uninstall script touches the filesystem. Personal data reliably enters the platform and unreliably leaves it.

**4. State is derived from the nearest available column rather than the one that means it.** Booking status stands in for attendance, an insurance flag stands in for enrolment (H-5). Both read plausibly and are wrong in the same direction — over-reporting entitlement — because the column that would carry the truth was never modelled.

**5. Config that silently degrades instead of failing closed.** An empty `APP_SECRET` yields a valid-looking token (H-1); an unrecognised route yields a valid-looking page (M-3, L-2); an unimplemented Zoom mode yields a valid-looking email (M-6). In each case the safe behaviour — refuse, 404, reject — was available and the code chose the quiet path.

**6. A fix applied narrowly where the class was general.** The root `_*.php` scratch scripts were denied in `.htaccess:33-35` after they were found reachable and dumping submitter data — but `plugins/*/tools/` was never covered, and six such scripts are still exposed (H-0). The five directory denials written in the same file have never worked at all under this install's sub-path (M-0). The remediation instinct is right and the follow-through stops at the first instance; the same is true of the diverged-copy findings in pattern 1, where the correct version exists but was never propagated.

---

## What I could not check, and why

- **Runtime behaviour.** This is a static read. No code was executed, no database queried, no request issued. Every finding is derived from source; nothing is confirmed against the running system.
- **The deployed `APP_SECRET`.** `.env` is gitignored and absent from this worktree, so H-1's exploitability is bounded but unresolved. Check `admin/settings.php:1710-1720`.
- **The actual PHP/MySQL offset on the live host.** H-4's four-hour figure is the codebase's own claim (`helpers.php:202-203`). The mismatch is structural regardless; the magnitude is not independently confirmed.
- **The host's `register_argc_argv` setting**, which decides whether H-0 is disclosure-only or unauthenticated data destruction. Not readable from the repository.
- **Plugins surveyed but not audited**: `clientdesk`, `timeclock`, `small-business-kit`, `sitehub`, `seo`, `slate-mcp`, `media-library`, `shop-emails`. Their tables appear in the inventory; their code does not. Given H-0, the first thing to check in each is whether it ships a `tools/` or `bin/` directory.
- **`studio` was audited as a follow-up** after the four main clusters — see `audit/raw/studio.md`. It produced H-0 and M-0, and is otherwise the strongest plugin in the codebase: the only spine adopter, the only one whose migrations have working rollbacks, complete admin gating, and a correct portal ownership model. It should be cited as the target shape for identity consolidation, not as a problem.
- **Core `admin/` and `src/` were read only where a plugin finding led into them** — `Auth`, `Database`, `helpers`, `PublicRouter`, `TenantContext`, `Uploads`. `admin/` (10,290 lines) and most of `src/` (10,447) had no dedicated pass, so core-only defects would have been missed.
- **JavaScript.** Client-side code was read only where server behaviour depended on it. `content-builder/admin/assets/builder.js` and `public/assets/slate-content.js` (548 lines, new) were not audited.
- **Stripe Terminal end-to-end.** `StripeTerminalAPI.php` is a real implementation, not a stub — but I did not exercise it against hardware and make no claim it works.
- **The browser audit document.** `claude/slate-duplication-audit.md` does not exist in this tree, so I could not check its findings one by one. I worked from the summary in the brief instead, and where the code contradicted it I have said so explicitly — most importantly on membership and coaching, which do *not* duplicate identity, and on admin authorization, which is complete everywhere I looked.
