# Booking / Booking+ audit (read-only)

Scope: `plugins/booking/`, `plugins/booking-plus/` (9,411 lines PHP).
Tree audited: `claude/slate-platform-audit-c95b8f` @ c3a90ba (= `develop` + Phase C/D content-spine commits; none touch booking).

---

## Inventory

### Tables

**booking** (`plugins/booking/install.sql`, + `migrations/0.2.0.sql`, `0.4.0.sql`, `0.5.0.sql`, `0.5.1.sql`)

| table | tenant_id | person columns |
|---|---|---|
| `booking_services` | YES | — |
| `booking_providers` | YES | `name`, `email`, `timezone`, `bio` |
| `booking_provider_services` | **NO** | — (join) |
| `booking_provider_hours` | YES | — |
| `booking_appointments` | YES | `customer_name`, `customer_email`, `customer_phone`, `notes`, `custom_json` |
| `booking_customers` | YES | `email`, `name`, `phone`, `customer_id`, `booking_count` |
| `booking_categories` | YES | — |
| `booking_locations` | YES | — |
| `booking_resources` | YES | — |
| `booking_service_resources` | **NO** | — (join) |
| `booking_service_addons` | YES | — |
| `booking_custom_fields` | YES | — (stores answers in `booking_appointments.custom_json`) |
| `booking_provider_breaks` | YES | — |
| `booking_date_overrides` | YES | — |
| `booking_coupons` (0.5.0) | YES | — |
| `booking_gift_cards` (0.5.0) | YES | — |

**booking-plus** (`plugins/booking-plus/install.sql`) — all three carry `tenant_id`:
`bookingplus_service_config`, `bookingplus_slot_restrictions`, `bookingplus_appointment_meta` (holds `client_message`, free-text client PII).

Neither plugin uses the canonical identity spine (`contacts` / `contact_emails` / `identities`, `db/migrations/0002_identity_core.php`). `booking_customers` is a third independent person model alongside core `customers` and the spine.

### Auth gating — admin screens

Every one of the 17 booking admin screens and 4 booking-plus admin screens calls `Auth::require()` followed by `Auth::requirePerm(...)` on lines 8–13. Verified individually; **no unprotected admin screen was found in either plugin.** `admin/invoice.php:24-25` gates conditionally but correctly (token path is tenant-scoped, id path requires `booking.view`).

Permissions used: `booking.view`, `booking.manage_appointments`, `booking.manage_services`, `booking.manage_providers`, `booking.manage_resources`, `booking.manage_payments`, `booking.manage_settings`, `bookingplus.manage_settings`, `bookingplus.reply_messages`.

### Public routes (unauthenticated by design)

| route | credential | tenant-scoped |
|---|---|---|
| `booking/public/router.php` `/book` | none (public widget) | yes, via `BookingAPI::getService()` |
| `booking/public/router.php` `/book/manage` | 32-hex `manage_token` | YES (`:1127`) |
| `booking/public/router.php` `/book/pay/done` | 32-hex `manage_token` | YES (`:1238`) |
| `booking/public/pay-intent.php` | 32-hex `manage_token` | YES (`:65`) |
| `booking/admin/invoice.php?token=` | 32-hex `manage_token` | YES (`:20`) |
| `booking-plus/public/message.php` | `manage_token` | **NO** — finding 1 |
| `booking-plus/public/prereq.php` | none | yes (`:31`, `:35`) |

### Settings keys

Written by `booking/admin/settings.php`: `booking.reminder_leads`, `booking.followup_enabled`, `booking.followup_delay_hours`, `booking.show_coupon`, `booking.show_gift_card`, `booking.show_repeat`, `booking.loyalty_points_per_booking`, `booking.sms_enabled`, `booking.whatsapp_enabled`, `booking.twilio_sid`, `booking.twilio_token`, `booking.twilio_sms_from`, `booking.twilio_whatsapp_from`.

Written by `booking-plus/admin/settings.php`: `bookingplus.whatsapp_url`, `bookingplus.nudge_hours`, **`booking.reminder_leads`**.

Written by `booking-plus/BookingPlus.php:59` at boot: **`booking.reminder_leads`**.

Read-only: `booking.notify_admin_email`, `site_admin_email`, `site_name`, `business_name`, `business_email`, `business_address`, `stripe-payment.*`.

**`booking.reminder_leads` has three writers across two plugins** — finding 5.

### Messaging / scheduling

- `BookingAPI::sendConfirmation` (`:1416`), `sendReminder` (`:1451`), `sendFollowup` (`:1471`), `sendStaffNotification` (`:1481`), `sendCancellation` (`:1498`), `sendReschedule` (`:1510`).
- Channels: `notifySms` (`:1550`), `sms` (`:1562`), `whatsapp` (`:1569`), `twilioSend` (`:1578`).
- Cron: `Booking::sendReminders()` (`Booking.php:315`) — leads + follow-ups. `BookingPlus` nudge cron (`BookingPlus.php:380`).
- `BookingPlus` filters reminder bodies via per-service templates (`BookingPlus.php:295-330`).

### Duplicated helpers

- **Money formatting** re-implemented per file: `admin/invoice.php:46` `fn($cents) => $cur . ' ' . number_format($cents/100, 2)`; similar local closures in `admin/appointment.php`, `public/router.php`. No shared core currency helper is used. Currency *is* carried per-service (`booking_services.currency`), so it is a value+code pair at rest — it degrades to a display string only at render.
- **Template placeholder substitution** exists twice: `BookingAPI::renderTemplate()` (`:1535`) and `BookingPlusAPI::renderTemplate()` (`BookingPlusAPI.php:152`, documented as "extends" the former).
- **Slot/interval maths** is single-sourced in `BookingAPI::effectiveIntervals()` — booking-plus correctly reuses it rather than copying. Good.
- `bookpub_*` render helpers in `public/router.php` are local to that file; not duplicated elsewhere.

---

## Findings

### [high] `booking-plus/public/message.php` resolves a booking by `manage_token` without a tenant filter, so a token from one tenant opens and writes against another tenant

- evidence: `plugins/booking-plus/public/message.php:32-40`
  ```sql
  WHERE a.manage_token = ?
    AND a.starts_at >= NOW() - INTERVAL 7 DAY
  LIMIT 1
  ```
  Compare the four sibling lookups of the same token, all of which do scope: `plugins/booking/admin/invoice.php:20` `WHERE a.manage_token = ? AND a.tenant_id = ?`; `plugins/booking/public/router.php:1127`; `plugins/booking/public/router.php:1238`; `plugins/booking/public/pay-intent.php:65`.
- failure case: a customer of tenant B holds their own `manage_token` (mailed to them after booking). They open `https://<tenant-A-host>/plugins/booking-plus/public/message.php?t=<B's token>`. The query matches B's appointment because nothing binds it to A. The page renders B's `customer_name`, `service_name`, `provider_name` and appointment time to a visitor on A's site (`:80-88`). On POST, `BookingPlusAPI::saveAppointmentMeta()` (`BookingPlusAPI.php:126-146`) looks for a meta row `WHERE tenant_id = A AND appointment_id = <B's id>`, finds none, and **inserts a new row stamped `tenant_id = A`** (`:144-146`). `bookingplus_notify_therapist()` then emails the message to `Database::setting('booking.notify_admin_email')` — read for tenant **A** (`BookingPlus.php:398`). Net result: tenant B's client message and PII are delivered to tenant A's admin inbox and stored under A's tenant id; tenant B's therapist never receives it, and B's nudge cron (`BookingPlus.php:384-395`, filtered `WHERE m.tenant_id = ?`) never fires because the row lives under A.
- blast radius: any multi-tenant install with booking-plus active; the public message endpoint; cross-tenant PII disclosure in both directions plus corrupted `bookingplus_appointment_meta` rows. Requires possession of a valid token (32-hex, not guessable), which is why this is high rather than critical.
- fix: add `AND a.tenant_id = ?` bound to `current_tenant_id()` to the lookup, matching the four sibling queries. Because the same token lookup is now written five times with one copy diverged, the durable fix is to collapse all five onto a single `BookingAPI::findByManageToken()` that scopes by tenant internally, so a future sixth caller cannot reintroduce the gap. Tighten the token regex at `:22` (`/^[a-zA-Z0-9]{16,64}$/`) to the `/^[a-f0-9]{32}$/` the rest of the plugin uses, since that is the format `createAppointment` actually issues.
- **verified**

### [high] Appointment reminders and follow-ups compare a PHP-clock timestamp against the MySQL clock, so they fire at the wrong time whenever the two disagree

- evidence: `starts_at`/`ends_at` are written from PHP — `plugins/booking/BookingAPI.php:489-490`
  ```php
  $endsAt      = date('Y-m-d H:i:s', $endTs);
  $startsAtSql = date('Y-m-d H:i:s', $startTs);
  ```
  and compared against the MySQL clock — `plugins/booking/Booking.php:326-327`
  ```sql
  AND a.starts_at > NOW()
  AND a.starts_at <= NOW() + INTERVAL ? MINUTE
  ```
  same pattern at `Booking.php:349-350` (follow-ups), `Booking.php:151`/`:155` (dashboard widget), `admin/index.php:24`/`:35-36` (today + next-7-days). `includes/helpers.php:200-207` states the platform's own position: *"PHP and MySQL do not necessarily share a timezone — on this host PHP runs UTC and MySQL runs SYSTEM, a four-hour gap — and mixing the two clocks produces comparisons that are silently wrong by that offset."* `slate_db_now()` exists as the remedy; `grep slate_db_now plugins/booking plugins/booking-plus` returns **zero** hits.
- failure case: a customer books 09:00 tomorrow. `strtotime()` and `date()` both run in PHP's timezone and cancel out, so the literal string `'2026-09-02 09:00:00'` is stored — a wall clock in *PHP's* zone. The 60-minute reminder lead selects rows where `starts_at` is between MySQL's `NOW()` and `NOW() + 60 min`. With MySQL four hours behind PHP, at real-world 08:00 MySQL believes it is 04:00, so the window is 04:00–05:00 and the 09:00 row is not selected. The row only enters the window when MySQL's clock reaches 08:00 — real-world 12:00, three hours *after* the appointment started. The `starts_at > NOW()` guard keeps the row eligible until MySQL reaches 09:00, so the "1 hour before" reminder is actually delivered up to four hours *late*, after the session. The follow-up query (`ends_at <= NOW() - INTERVAL ? HOUR`) is shifted the same way, and "Today's appointments" (`DATE(a.starts_at) = CURDATE()`) shows the wrong day for a four-hour band around midnight.
- blast radius: every reminder and follow-up email/SMS on every tenant running booking; the admin dashboard KPI widget and the Booking index "today"/"next 7 days" counts. Dormant only if PHP and MySQL happen to share a timezone on a given host.
- fix: decide one clock and use it for both write and compare. The cheapest correct change is to write `starts_at`/`ends_at` through the database clock the same way the rest of the platform does — derive the stored value from a MySQL-side expression, or normalise the PHP-parsed wall-clock into the database's zone before formatting — so that the `NOW()` comparisons in the cron and dashboards are apples-to-apples. Changing the comparisons to PHP-side timestamps instead would work equally well but touches more call sites. Whichever direction is chosen, the appointment tables should be swept for existing rows written under the old convention, because a straight fix shifts the meaning of every historical `starts_at` by the offset.
- **verified** (the mismatch is structural and provable from the code; the specific four-hour magnitude is the codebase's own assertion at `helpers.php:202-203`, not something I measured on the live host)

### [medium] Booking+'s reply-nudge SLA is off by the PHP/MySQL clock offset, so the "8 hours" the client is promised is not the interval enforced

- evidence: written on the PHP clock — `plugins/booking-plus/public/message.php:63,67`
  ```php
  $now = date('Y-m-d H:i:s');
  … 'therapist_notified_at' => $now,
  ```
  compared on the MySQL clock — `plugins/booking-plus/BookingPlus.php:394`
  ```sql
  AND m.therapist_notified_at <= NOW() - INTERVAL ? HOUR
  ```
  Same defect in the two other PHP-clock stamps on this table: `nudge_sent_at` (`BookingPlus.php:414`) and `therapist_replied_at` (`booking-plus/admin/index.php:28`).
- failure case: the client is told on screen "I'll get back to you within 8 hours" (`message.php:70-71`, interpolating `globalNudgeHours()`). A message sent at real-world 10:00 stores `therapist_notified_at = '10:00'` on the PHP clock. With MySQL four hours behind, at real-world 18:00 MySQL's `NOW() - 8 HOUR` evaluates to 06:00, and `'10:00' <= '06:00'` is false — the nudge does not fire. It fires when MySQL's clock reaches 18:00, i.e. real-world 22:00: a 12-hour effective SLA against an 8-hour promise. If the offset ran the other way the nudge would fire four hours early, mailing the therapist about a message they still have time to answer.
- blast radius: the Booking+ nudge cron and the unanswered-message admin widget (`BookingPlus.php:99-105`) on every tenant with booking-plus active.
- fix: same remedy as the finding above and the same one `slate_db_now()` was written for — stamp these three columns from the database clock rather than PHP's, so the cron's `NOW()` arithmetic measures the interval it claims to. These columns are only ever compared, never displayed as a local time, so there is no display-side consequence to weigh.
- **verified**

### [medium] The Twilio auth token is stored in the settings table in cleartext, while the platform's secret-encryption helper is used for comparable secrets elsewhere

- evidence: `plugins/booking/admin/settings.php:47-48`
  ```php
  $token = trim((string)($_POST['twilio_token'] ?? ''));
  if ($token !== '') Database::setSetting('booking.twilio_token', $token);
  ```
  read back raw at `plugins/booking/BookingAPI.php:1580`. `grep slate_encrypt_secret plugins/booking plugins/booking-plus` returns zero hits, whereas the same helper *is* used for equivalent secrets at `includes/SmtpOAuth.php:96`, `admin/oauth_callback.php:74-75`, `admin/settings.php:139` and `plugins/sitehub/SitehubAPI.php:33`. The form itself treats the value as a secret — `admin/settings.php:157` renders it as `type="password"` with a `••••••••` placeholder — so the intent is clearly confidentiality.
- failure case: any read of the `settings` table yields a live Twilio credential — a database backup or dump, a read-only reporting connection, a support engineer with DB access, or the `admin/repair-settings.php` screen. The credential permits sending SMS and WhatsApp messages billed to the tenant's Twilio account, and Twilio auth tokens also authorise account-level API calls. The masked form field means an operator reasonably believes the value is protected at rest.
- blast radius: any tenant that has configured SMS or WhatsApp in Booking → Settings. Note `booking.twilio_sid` is an account identifier rather than a secret; the token is the sensitive half.
- fix: wrap the value in `slate_encrypt_secret()` on write and `slate_decrypt_secret()` on read, exactly as `admin/settings.php:136-139` already does for the SMTP password — including that screen's handling of the case where `APP_SECRET` is unset, since `slate_encrypt_secret()` throws rather than silently storing plaintext. Existing stored tokens are cleartext and will not carry the `enc:v1:` prefix; `slate_decrypt_secret()` returns such input unchanged (`helpers.php:231`), so reads keep working during migration and values re-encrypt on next save. Worth noting `APP_SECRET` defaults to empty (`config.php:49`), so this fix is only real once that is provisioned.
- **verified**

### [medium] `booking.reminder_leads` has three writers across two plugins, and Booking+ silently overwrites it on every request, making the documented default unsettable

- evidence: three writers —
  `plugins/booking/admin/settings.php:28-30`
  ```php
  $leadsCsv = implode(',', array_keys($leads)) ?: '1440,60';
  Database::setSetting('booking.reminder_leads', $leadsCsv);
  ```
  `plugins/booking-plus/admin/settings.php:25-28` (writes only when the field is non-empty), and `plugins/booking-plus/BookingPlus.php:56-61`
  ```php
  $cur = trim((string) (Database::setting('booking.reminder_leads') ?? ''));
  if ($cur === '' || $cur === '1440,60') {
      Database::setSetting('booking.reminder_leads', BookingPlusAPI::defaultReminderLeads());
  ```
  called from `boot()` at `BookingPlus.php:24`, i.e. on every request.
- failure case: an operator opens **Booking → Settings**, clears the reminder-leads field and saves, intending the stock 24h + 1h cadence. Line 28 converts the empty input to `'1440,60'` and stores it. On the very next page load Booking+ boots, `maybeSeedReminderLeads()` sees exactly `'1440,60'`, treats it as "still on booking's default", and replaces it with Booking+'s 8-day/1-day/10-minute cadence. The Booking settings screen then displays lead times the operator never entered, and reminders fire on a schedule they did not choose. `'1440,60'` is unreachable as a saved value for as long as Booking+ is active — the one cadence that is documented as the default is the one cadence that cannot be set. Which writer wins is deterministic (the boot seed runs last, on every request) but is the opposite of what the two settings screens imply.
- blast radius: any tenant running both plugins — the live tenant does. Two admin screens present the same key as independently editable.
- fix: the seed's "is this still the default?" test cannot distinguish an untouched default from a deliberate choice of the same value, so it needs a separate marker — a one-shot flag recording that the seed has run, checked instead of the value's content. That makes the seed genuinely one-time and lets `'1440,60'` be chosen. Beyond that, one key should have one editor: either Booking+ stops writing `booking.reminder_leads` and reads it, or the field is removed from Booking → Settings and Booking+ owns it, with the other screen linking across. Two screens editing one key with different validation (Booking coerces empty to a default, Booking+ ignores empty) will keep producing surprises regardless of the seed.
- **verified**

### [medium] Booking's `uninstall.sql` drops 5 of the 16 tables it creates, leaving customer PII in the database after uninstall

- evidence: `plugins/booking/uninstall.sql` drops only `booking_appointments`, `booking_provider_hours`, `booking_provider_services`, `booking_providers`, `booking_services`. Not dropped, though created by `install.sql` / `migrations/0.2.0.sql` / `migrations/0.5.0.sql`: `booking_customers`, `booking_categories`, `booking_locations`, `booking_resources`, `booking_service_resources`, `booking_service_addons`, `booking_custom_fields`, `booking_provider_breaks`, `booking_date_overrides`, `booking_coupons`, `booking_gift_cards`. Contrast `plugins/booking-plus/uninstall.sql`, which does drop all three of its tables.
- failure case: an operator uninstalls Booking — plausibly *in order to* remove customer data. `booking_customers` survives with every customer's `name`, `email`, `phone` and booking history intact, indefinitely, with no UI left that can read or delete it: `BookingAPI::deleteCustomerData()` (`BookingAPI.php:1387`) is the only erasure path and it ships with the plugin that was just removed. A GDPR erasure request received after uninstall cannot be serviced through the product. Separately, reinstalling Booking re-runs `install.sql` with `IF NOT EXISTS`, so the new install silently inherits the previous era's customer rows, coupon usage counts and gift-card balances.
- blast radius: any tenant that uninstalls Booking; personal data retention and right-to-erasure obligations.
- fix: extend `uninstall.sql` to drop every table the plugin creates across `install.sql` and all four migrations, in FK-safe order, mirroring what booking-plus already does correctly. The generic problem is that the uninstall script was written against the original five-table schema and no migration since has updated it, so the safer structural fix is to derive the drop list from the same manifest the installer uses rather than maintaining it by hand. Whether uninstall *should* destroy customer data is a product decision worth making explicitly — but silently orphaning it is the one option that serves neither retention nor erasure.
- **verified**

### [medium] Zoom `api` mode is selectable and persisted but has no implementation, so reminders for those services render an empty join link with no fallback

- evidence: the option is offered — `plugins/booking-plus/admin/service.php:238`
  ```php
  'api' => 'API — real Zoom OAuth (Phase 1.5, not wired)',
  ```
  and accepted by the validator — `plugins/booking-plus/BookingPlusAPI.php:86-87`
  ```php
  if (isset($data['zoom_mode']) && !in_array($data['zoom_mode'], ['manual','fallback_message','api'], true)) {
      $data['zoom_mode'] = 'fallback_message';
  ```
  but the only consumer handles just two modes — `plugins/booking-plus/BookingPlus.php:314-317`
  ```php
  $zoom = trim((string)($meta['zoom_join_url'] ?? '')) ?: trim((string)($cfg['zoom_join_url'] ?? ''));
  if (($cfg['zoom_mode'] ?? '') === 'fallback_message' && $zoom === '') {
  ```
  No Zoom OAuth client, API call, or writer of `zoom_join_url` under `api` mode exists anywhere in the plugin.
- failure case: an operator sets a service to Zoom mode `api`. Nothing populates `zoom_join_url`, so `$zoom` resolves to the empty string; the `fallback_message` branch does not run because the mode is `api`; `{{zoom_url}}` is substituted as empty into every confirmation and reminder template (`BookingPlus.php:328`). Clients receive a "here is your Zoom link" email with no link and no explanatory fallback line. The services list reinforces the illusion by rendering the mode as configured — `admin/services.php:75-76` labels it `API (Phase 1.5)` alongside the working `Manual (link set)` state rather than flagging it as inert.
- blast radius: any service configured with `zoom_mode = 'api'`; every confirmation and reminder for that service's appointments.
- fix: until the integration exists, the option should not be selectable — remove it from the `<select>` and reject the value in `saveServiceConfig()` so it cannot be persisted, or disable the option and have it fall through to `fallback_message` behaviour at render time so clients at least get the explanatory line. The parenthetical "not wired" in the label is not sufficient: the value saves, the list screen reports it as a configured mode, and the failure is silent and client-facing.
- **verified**

### [low] The two join tables carry no `tenant_id`, so the tenant boundary there rests on id-uniqueness rather than on a filter

- evidence: `booking_provider_services` and `booking_service_resources` are the only two booking tables without a `tenant_id` column (`plugins/booking/install.sql`; `migrations/0.2.0.sql`). They are queried without one — `plugins/booking/BookingAPI.php:461-465`
  ```sql
  SELECT 1 FROM booking_provider_services WHERE provider_id = ? AND service_id = ?
  ```
  and `BookingAPI.php:270-273` (`effectiveDuration`).
- failure case: **I could not construct one.** `provider_id` arrives from the request and is never tenant-checked directly, but `service_id` is validated through `getService()` (`:144-152`, tenant-scoped) and service ids are globally unique across tenants in a shared table, so only the owning tenant can ever have created a join row referencing that id. The association check is therefore transitively tenant-safe today. It is reported as low because the safety is incidental — it depends on ids being globally unique and on `getService()` being called first, neither of which is enforced or documented at the join table. A future caller that reaches `effectiveDuration()` or the association check without first resolving the service through `getService()` would have no boundary at all.
- blast radius: none currently; a latent constraint on how these two tables may be queried in future.
- fix: add `tenant_id` to both join tables and filter on it, so the boundary is stated in the schema rather than inferred from id allocation. This is defence-in-depth, not a live vulnerability, and should be scheduled rather than hotfixed.
- **verified as not currently exploitable** — reported for the latent constraint only

---

## What I checked and found clean

Recording these so the reconciler does not re-litigate them, and because several contradict what a browser-level audit would reasonably have assumed:

- **Admin authorization is complete.** All 21 admin screens across both plugins call `Auth::require()` + `Auth::requirePerm()`. No screen relies on nav visibility.
- **CSRF coverage is complete** on every state-changing admin POST (18 of 18 files) and on both public POST paths (`router.php:60`, `message.php:53`). `public/pay-intent.php` has no `csrf_verify()` but is not CSRF-relevant: it is POST-only, authenticated by a 32-hex unguessable token that an attacker forging a cross-site request would not possess, and it performs no session-authorised action.
- **Booking price and capacity are computed server-side.** `createAppointment` (`BookingAPI.php:404-560`) re-derives totals from the database via `computeTotals()` (`:854`), never from the request; capacity is reserved inside a transaction with `SELECT … FOR UPDATE` (`:539-547`) so concurrent bookings cannot oversell a slot. Coupon and gift-card redemption are atomic, cap-respecting single-statement updates (`:927-969`).
- **The Stripe return path binds payments to the correct appointment** via `metadata.booking_appt_id` before marking paid, and logs a mismatch rather than trusting it (`router.php:1255-1310`).
- **File upload handling is hardened** (`router.php:249-291`): extension allowlist, `finfo` MIME verification, 8 MB cap, random filename, and an `.htaccess` disabling PHP execution in the upload directory.
- **Double-submit is handled** — `submit_token_consume()` at `router.php:65`, so a refresh or double-click cannot create a second appointment.
- **No SQL string concatenation of user input.** The two `implode(' AND ', $where)` sites (`admin/appointments.php:35`, `BookingAPI.php:1337`) build fragments from hardcoded literals with bound parameters.
- **All other tenant-scoped queries filter `tenant_id`.** The apparent misses in a line-based grep are multi-line statements with the filter on a following line, or follow-up writes by an id already proven to belong to the tenant.

---

## Notes for the reconciler

1. **The PHP/MySQL clock split is not a booking bug — it is a platform pattern.** `includes/helpers.php:193-213` documents that it has already caused two production incidents (the login throttle and membership expiry). Booking never calls `slate_db_now()`. Every other plugin should be swept for the same write-in-PHP / compare-in-MySQL shape; expect this to be the largest cross-cutting cluster in the final report.
2. **`booking_customers` is a fourth person model.** Core has `customers` (`db/schema.sql`) and the canonical spine `contacts`/`identities` (`db/migrations/0002_identity_core.php`), and booking adds `booking_customers` keyed on `tenant_id + email` with a nullable `customer_id` link. Only `plugins/studio` uses the spine. This is the schema-level confirmation of the browser audit's central finding for these two plugins.
3. **GDPR erasure is per-plugin and incomplete.** `BookingAPI::deleteCustomerData()` (`:1387`) anonymises `booking_appointments` and deletes from `booking_customers`, but does not touch `bookingplus_appointment_meta.client_message` — free-text client PII that survives a booking erasure entirely. Cross-check whether any core deletion path fans out to plugins; if not, every plugin's person table needs its own erasure hook and none of them are wired together.
4. **The five copies of the `manage_token` lookup** are worth checking against any other plugin that consumes booking tokens.
5. **Migrations are forward-only.** `plugins/booking/migrations/*.sql` are plain SQL with no rollback counterpart, unlike core's PHP migrations which implement `down()` (e.g. `db/migrations/0002_identity_core.php:97-105`). Whether the plugin migration runner is expected to support rollback at all is a core question — check `src/Data/MigrationRunner.php` and `src/Kernel/Module/PluginLoader.php` before reporting this platform-wide.
