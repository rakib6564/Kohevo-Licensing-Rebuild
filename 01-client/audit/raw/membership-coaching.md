# Membership / Coaching audit (read-only)

Scope: `plugins/membership/`, `plugins/coaching/` (10,051 lines PHP).
Tree audited: `claude/slate-platform-audit-c95b8f` @ c3a90ba.

---

## Named bugs

### Bug 1 — "✓ Present" for a session that has not happened yet

**Cause.** `plugins/membership/public/views/home.php:87`

```php
$present = in_array($a['status'], ['confirmed','completed'], true);
```

The row source is `MembershipAPI::recentAttendance()` (`plugins/membership/MembershipAPI.php:752-763`), which applies **no time filter at all** — its own docblock says "past + imminent":

```sql
SELECT a.starts_at, a.status, s.name AS service_name
  FROM booking_appointments a
  LEFT JOIN booking_services s ON s.id = a.service_id
 WHERE a.tenant_id = ? AND a.customer_id = ?
 ORDER BY a.starts_at DESC LIMIT …
```

`ORDER BY starts_at DESC` puts the *furthest-future* appointment first, so the top of "Recent attendance" is the appointment least likely to have happened.

**What it currently keys off:** the booking's `status` column alone. In booking's vocabulary `confirmed` is a *scheduling* state assigned at creation time — `plugins/booking/BookingAPI.php:530` sets `$status = $needsPayment ? 'pending' : 'confirmed'` the moment the booking is made, and `booking_appointments.status` defaults to `'confirmed'` (`BookingAPI.php:115`). It says nothing about whether anyone turned up.

**What it should key off:** attendance needs both a *time* predicate (the session has ended — `ends_at <= now`) and an *outcome* predicate. Only `completed` and `no_show` are outcome states. Note that `completed` is **never set automatically** — `BookingAPI::changeStatus()` (`BookingAPI.php:1282`) is called from exactly one place, `plugins/booking/admin/appointment.php:65`, a manual admin action, and no cron transitions past sessions. So keying purely on `status === 'completed'` would under-report for any tenant that does not mark sessions by hand; the query needs the time bound as the primary filter, with status distinguishing attended from no-show among sessions that have already ended.

**Concrete failure:** a member books a class for next Friday. It is `confirmed` immediately. Opening the member dashboard today, the top row of "Recent attendance" is next Friday's class with a green dot, the pill "✓ Present", and — via `home.php:97` — next Friday's *scheduled start time* printed as though it were the time they arrived.

**Blast radius is wider than the pill.** The same `IN('confirmed','completed')` predicate with no time bound drives every attendance metric in the portal:

- `sessionStats()['total']` — `MembershipAPI.php:742`, the headline "total attended"
- `sessionStats()['month']` — `MembershipAPI.php:744`, "this month"
- `sessionBreakdown()` — `MembershipAPI.php:771`, the category donut
- `weeklyActivity()` — `MembershipAPI.php:786`, the weekly bar chart

A member with six future bookings and zero attended sessions sees "6 sessions attended". `sessionStats()['missed']` is correct because `no_show` is only ever set retrospectively.

---

### Bug 2 — "Course progress" reports the member enrolled in every service, including ones with zero sessions

**Cause.** Two halves.

The list is every active service on the tenant, unfiltered by member — `plugins/membership/MembershipAPI.php:801`:

```php
$svcs = Database::rows("SELECT id, name FROM booking_services WHERE tenant_id=? AND is_active=1 ORDER BY name LIMIT 12", [$tid]);
```

And the "Enrolled" pill is simply the `else` branch of *locked* — `plugins/membership/public/views/home.php:257`:

```php
<?php if ($c['locked']): ?><span class="pill pill-amber">⚠ Locked</span>
<?php else: ?><span class="pill pill-green">✓ Enrolled</span><?php endif; ?>
```

where `locked` is an insurance test and nothing else — `MembershipAPI.php:817`:

```php
'locked'=> ($needsIns && !$hasIns),
```

**What it currently keys off:** the negation of "this service requires insurance the member lacks". Every active service that does not require insurance therefore renders "✓ Enrolled" for every member who opens the page.

**What it should key off:** an actual relationship between this member and this course. The data is already computed one line away — `used` (`MembershipAPI.php:811-814`) counts the member's own appointments for that service — but it is never consulted for the enrolled state. The deeper problem is that **no enrolment concept exists in the schema**: `plugins/membership/install.sql` defines `membership_plans`, `membership_subscriptions`, `membership_profiles`, `membership_wallet`, `membership_wallet_txns` and nothing that links a member to a course. `membership_plans.course_id` links a *plan* to one Booking service, so the closest available truth is "the member holds an active subscription whose plan's `course_id` is this service", optionally widened to "or has booked it at least once". Until an enrolment record exists, the pill is asserting a state the data model cannot express.

**Concrete failure:** a tenant has eight active booking services. A member who has ever booked exactly one of them opens the dashboard and sees all eight listed, seven of them reading "✓ Enrolled · 0 sessions" beneath an empty progress bar — `home.php:248` computes `$cp = … ($c['used']>0?100:0)`, giving a 0%-width bar directly above a green "Enrolled" badge. The contradiction between the empty bar and the badge is visible in a single row.

Two secondary effects at the same site: `LIMIT 12` (`:801`) silently truncates the list for any tenant with more than twelve active services, with no indication; and `$cq` is taken from the member's *active plan* quota (`home.php:21`) but applied to whichever row matches `$c['id'] === $courseId` (`home.php:247`), so every other row falls to the `used>0?100:0` branch and shows a full bar after a single booking.

---

## Inventory

### Tables

**membership** (`plugins/membership/install.sql`) — all five carry `tenant_id`; uninstall drops all five.

| table | person data |
|---|---|
| `membership_plans` | — |
| `membership_subscriptions` | — (FK `customer_id`) |
| `membership_profiles` | `gender`, `dob`, `skill_level`, **`medical_notes`**, **`allergies`**, `emergency_name`, `emergency_phone`, `emergency_relation`, `avatar_path`, `consent_*`, `qr_token` |
| `membership_wallet` | — |
| `membership_wallet_txns` | — |

**coaching** (`plugins/coaching/install.sql`) — all sixteen carry `tenant_id`; uninstall drops all sixteen.

`coaching_profile` holds `dob`, `gender`, `height_cm`, `weight_kg`, `body_measurements` (JSON), `body_type`, **`intolerances`** (JSON), `dietary_preferences`, **`pathologies`**, **`ongoing_care`**, **`alternative_medicine`**, **`personal_issues`**, `therapist_contact` (JSON), `bmi`/`bmr`/`tdee`. Plus `coaching_diary_photo.file_path` and `coaching_message.photo_path` — client body photographs.

**Neither plugin duplicates identity.** Both hang off core `customers` via `customer_id`, and both say so in their install headers. Neither uses the canonical spine (`contacts`/`identities`), but neither invents a person table either. This contradicts a blanket reading of the browser audit — see "Where the code contradicts the browser audit" below.

### Auth gating

All 5 membership admin screens and all 8 coaching admin screens call `Auth::require()` + `Auth::requirePerm(...)` in their first ~15 lines. Verified individually; **no unprotected admin screen in either plugin**.

Customer surfaces: `membership/public/router.php:31` and `coaching/customer/router.php:45` both call `Auth::requireCustomer()`. `membership/public/landing.php` is public by design (marketing/join page).

Permissions: `membership.view`, `membership.manage_plans`, `membership.manage_members`, `membership.manage_settings`; `coaching.view_clients`, `coaching.manage_clients`, `coaching.reply_chat`, `coaching.manage_library`.

### Public routes

`Membership::addPublicRoutes()` (`Membership.php:71-83`) registers `membership` → `public/landing.php` (GET) and `member` → `public/router.php` (GET, POST). Coaching registers a customer-portal route to `customer/router.php`.

### Settings keys

`membership.*`: `require_membership_to_book`, `require_profile_to_book`, `insurance_required_services`, `insurance_fee_cents`, `currency`. Written only by `membership/admin/settings.php`. `coaching.*` written only by `coaching/admin/settings.php`. **No key is written by two screens in either plugin** — unlike the booking/booking-plus pair.

Both read core `brand_accent_color`, `brand_logo_path`, `site_name`.

### Messaging

`MembershipAPI::sendPurchaseEmail()` (`:678`), `sendCancelEmail()` (`:699`); expiry-reminder cron guarded by `reminder_7d_sent` / `reminder_3d_sent` columns. Coaching: `coaching_thread` / `coaching_message` in-app chat, admin replies via `admin/chat.php`.

### Duplicated helpers

`MembershipAPI::money()` (`:142`) is a private currency formatter, parallel to booking's per-file `number_format` closures. No shared core money helper exists; three plugins now format currency their own way.

---

## Findings

### [high] Every attendance figure in the member portal counts sessions that have not happened yet

- evidence: `plugins/membership/public/views/home.php:87` `$present = in_array($a['status'], ['confirmed','completed'], true);` over rows from `MembershipAPI::recentAttendance()` (`plugins/membership/MembershipAPI.php:752-763`), which has no time predicate. Same unbounded predicate at `MembershipAPI.php:742`, `:744`, `:771`, `:786`.
- failure case: a member with six confirmed future bookings and zero completed sessions opens `/member`. The dashboard reports "6" total sessions attended, the donut attributes them to categories, the weekly chart plots them into the current month's week buckets, and the top "Recent attendance" row shows next Friday's class as "✓ Present" with next Friday's start time as the arrival time.
- blast radius: every member on every tenant running membership + booking; the member dashboard's headline KPI, category donut, weekly activity chart and attendance list. Staff reading the same figures from `admin/member.php` inherit them.
- fix: add a time bound to the shared predicate — attendance should count only sessions whose `ends_at` has passed — and separate "attended" from "scheduled" throughout. Because `completed` is only ever set by hand (`BookingAPI::changeStatus()` is called solely from `booking/admin/appointment.php:65`), the time bound must be the primary filter rather than relying on the status transition. The four aggregate methods and the list method all repeat the same `IN('confirmed','completed')` fragment, so the durable fix is one shared "attended sessions" query the five callers use, rather than five parallel edits. The list view additionally needs to stop ordering future-first, or split into "upcoming" and "past" sections.
- **verified**

### [high] "Course progress" shows "✓ Enrolled" for every active service, because enrolment is not modelled and the badge tests only insurance

- evidence: `plugins/membership/MembershipAPI.php:801` selects every active service on the tenant with no member predicate; `:817` sets `'locked' => ($needsIns && !$hasIns)`; `plugins/membership/public/views/home.php:257` renders "✓ Enrolled" as the `else` branch of `locked`. `plugins/membership/install.sql` contains no enrolment table.
- failure case: a tenant with eight active services; a member who has booked one. All eight appear under "Course progress"; the seven never booked read "✓ Enrolled · 0 sessions" with a zero-width progress bar. A ninth-through-Nth service is silently omitted by `LIMIT 12`.
- blast radius: every member on every tenant running membership + booking. The member is told they are enrolled in courses they have no relationship to, which is both misleading and a support burden.
- fix: the badge must reflect a real relationship. The narrow fix is to derive it from the `used` count already computed at `MembershipAPI.php:811-814`, or from the member's active subscription's `plan.course_id`, and to show unrelated services as "Available" rather than "Enrolled" — or omit them. The structural fix is to model enrolment explicitly, since the plugin currently has no way to express "this member is taking this course" independently of having booked it; the plan/course link is one-to-one via `membership_plans.course_id` and cannot represent a member enrolled in several. Whichever is chosen, `LIMIT 12` should become paginated or bounded by the member's own courses rather than truncating the tenant's catalogue.
- **verified**

### [high] `session_quota` is sold and displayed but never enforced, so a capped plan grants unlimited bookings

- evidence: the column exists and is editable — `plugins/membership/install.sql` (`membership_plans.session_quota`), written at `plugins/membership/admin/plans.php:69`, with the admin hint at `:255` reading "Number of sessions this plan includes. 0 = unlimited." Every other reference is display-only: `public/views/plans.php:51`, `public/views/home.php:21`. The booking gate — `plugins/membership/Membership.php:107-140`, hooked to `booking_can_book` — tests only an active membership (`:111`, `:122`), a completed profile (`:112`, `:128`) and insurance (`:132`). `grep session_quota` across `plugins/` returns no comparison, no decrement, and no rejection anywhere.
- failure case: a tenant creates a "10-session pass" priced accordingly and a member buys it. The member books an eleventh, twelfth and hundredth session; `Membership::canBook()` returns `['ok' => true]` every time because nothing consults the quota. The dashboard shows "11 / 10 sessions" — `home.php:248` caps the *bar* at `min(100, …)` but prints the raw count — so the overage is visible to the member while remaining unenforced. The plan's only real constraint is `duration_days`.
- blast radius: any tenant selling quota-based plans; direct revenue loss proportional to overage. Membership's plan model carries both a time axis (`duration_days`, `grace_days`) and a quota axis (`session_quota`), and only the time axis is enforced — exactly the mixed quota/time-plan concern.
- fix: the gate at `Membership::canBook()` is the right place, since it already receives the service and customer and already returns a blocking reason. It needs to count the member's consumed sessions for the current subscription term and refuse when the quota is met, which requires deciding what a session "consumes" — a booking at creation time, or an attended session — and what happens to a cancellation. Note this interacts with both bugs above: the natural counter is the same "attended sessions" query, so fixing the attendance predicate first gives the quota check a correct number to work from. Until enforcement exists, the admin hint overstates what the field does and should say so.
- **verified**

### [high] Member medical and health data has no deletion path anywhere in the platform

- evidence: membership stores special-category data — `membership_profiles.medical_notes`, `allergies`, `dob`, `gender`, `emergency_*` (`plugins/membership/install.sql`) — and coaching stores more — `coaching_profile.pathologies`, `ongoing_care`, `alternative_medicine`, `personal_issues`, `intolerances`, `height_cm`, `weight_kg`, `bmi`, plus body photographs at `coaching_diary_photo.file_path` and `coaching_message.photo_path`. Neither plugin has an erasure or export routine: `grep "function delete|function export" plugins/membership/MembershipAPI.php` returns nothing for member data (only plan deletion at `admin/plans.php:86-107`), and coaching's deletes (`CoachingAPI.php:386`, `:440`, `:511`, `:764`, `:827`, `:910`, `:994`) are all per-item, never per-client. More fundamentally, **core has no customer-deletion path at all**: there is no customers admin screen (`ls admin/` — none), no `DELETE FROM customers` anywhere in the tree, and no `customer_deleted` action for plugins to subscribe to (`src/Services/Auth/Auth.php` fires `customer_registered` `:690`, `customer_logged_in` `:595`, `customer_email_verified` `:744` — no delete).
- failure case: a member exercises their right to erasure. There is no screen, API or CLI path that removes their record. `admin/users.php:183-188` deletes *admin users* only. Even the workaround of uninstalling the plugin is incomplete: `uninstall.sql` drops the tables but cannot remove the avatar and diary-photo **files** already written under `uploads/`, which persist with no database row left to locate them by. Booking, by contrast, does ship `deleteCustomerData()` (`plugins/booking/BookingAPI.php:1387`) — but it is keyed by email, scoped to booking's own tables, and wired to nothing, so it would not touch `membership_profiles` or any `coaching_*` table even if invoked.
- blast radius: every tenant holding member health data — the live tenant does. This is the highest-consequence gap found in these two plugins: special-category data under GDPR Article 9, with no Article 17 path.
- fix: this needs a core capability, not two plugin patches. Core should own a customer-erasure entry point that fires an action plugins subscribe to, so each plugin removes its own rows and files and the caller gets one auditable operation; booking's existing `deleteCustomerData()` becomes the first subscriber and membership and coaching add theirs. Erasure must cover the filesystem as well as the database, since avatars and diary photographs live under `uploads/` and outlive any table drop. A matching export path is worth building at the same time, because the same fan-out serves Article 15 access requests and booking already demonstrates the shape (`exportCustomerData()`, `BookingAPI.php:1378`).
- **verified**

### [medium] Any path under `/member` silently renders the Home dashboard with HTTP 200 instead of 404

- evidence: `plugins/membership/public/router.php:34` reads the view from the query string only — `$view = (string)($_GET['view'] ?? 'home');` — and `:244-246` falls through to Home for anything unrecognised:
  ```php
  $known = ['onboarding', 'profile', 'schedule', 'card', 'plans', 'wallet', 'home'];
  $v = in_array($view, $known, true) ? $view : 'home';
  require $views . '/' . $v . '.php';
  ```
  The router never reads `_route_path`, which is how the platform passes the path remainder: `src/Kernel/Http/PublicRouter.php:100` sets `$_GET['_route_path'] = $remainder`, and `PublicRouter` renders a real 404 at `:119` only when *no plugin claims the prefix*. Booking's router does consume it — `plugins/booking/public/router.php:39` `$routePath = trim((string)($_GET['_route_path'] ?? ''), '/');`.
- failure case: a member opens `/slate/member/profile` — the obvious URL, and the shape the router's own docblock implies at `:3`. `PublicRouter` matches the `member` prefix, sets `_route_path = 'profile'`, and hands off. Membership reads `$_GET['view']`, finds it unset, and renders **Home** with HTTP 200. A typo like `/member?view=profil` does the same. Nothing 404s, so a bookmark or shared link to a member page silently lands somewhere else, and monitoring sees a healthy 200.
- blast radius: every path-style URL under `/member` on every tenant. In-app navigation is unaffected because the tabs at `router.php:231` all emit `?view=` links — which is why this survives casual testing.
- fix: the router should resolve the view from `_route_path` first and fall back to `?view=`, so both URL shapes work, and should emit a 404 through the platform's error page for an unrecognised view rather than defaulting to Home. Defaulting to Home is only correct for the bare `/member` root. Worth checking the other portal routers for the same omission, since booking gets this right and membership does not — the contract is established but not followed uniformly.
- **verified**

### [medium] Date of birth, gender and medical information are collected twice under different names and incompatible types, with nothing reconciling them

- evidence: the same member entering both flows fills in two independent records.
  - DOB — `membership_profiles.dob DATE NULL` vs `coaching_profile.dob DATE NULL`.
  - Gender — `membership_profiles.gender ENUM('female','male','other','undisclosed') NOT NULL DEFAULT 'undisclosed'` vs `coaching_profile.gender ENUM(…) NULL`. Same value set, different nullability, so "not asked" and "declined to say" are distinguishable in one table and conflated in the other.
  - Allergies/intolerances — `membership_profiles.allergies TEXT` vs `coaching_profile.intolerances JSON`. The same clinical concept as free text in one place and a structured array in the other; neither can be read by the other's code.
  - Medical notes — `membership_profiles.medical_notes TEXT` vs coaching's four-way split `pathologies` / `ongoing_care` / `alternative_medicine` / `personal_issues`.
  - Emergency contact — `membership_profiles.emergency_name` + `emergency_phone` + `emergency_relation` (three columns) vs `coaching_profile.therapist_contact JSON`.
  Both are written from their own forms — `plugins/membership/public/router.php:82-87` and `:116-120`; coaching's profile save in `plugins/coaching/customer/router.php`. No code reads across.
- failure case: a member of a studio that runs both plugins completes membership onboarding (`/member?view=onboarding`, step 2) declaring a peanut allergy in `membership_profiles.allergies`, then completes the coaching intake and records it again in `coaching_profile.intolerances`. Later they update it in one place only. Two records now disagree about a medical fact, and there is no authoritative one: a coach reading the coaching profile and a receptionist reading the membership profile see different allergy information for the same person. Nothing detects or surfaces the divergence.
- blast radius: any tenant running both plugins. Duplicate data entry for the member, and a safety-relevant inconsistency for allergy and medical fields specifically. The type divergence (TEXT vs JSON) means this cannot be resolved by pointing one plugin at the other's column.
- fix: these fields belong to the person, not to the plugin, and should live once on a shared profile that both read — the natural home is the identity spine (`contacts` and its satellites, `db/migrations/0002_identity_core.php`), which exists for exactly this and is currently used only by `plugins/studio`. That is a migration, not a patch: the two tables hold live data in incompatible shapes, so consolidating means choosing a canonical representation for each field (structured for allergies, given the safety implications), backfilling both sources, and resolving conflicts with a human in the loop. Short of that, the minimum is to designate one table authoritative per field and have the other read through, so the member is asked once.
- **verified**

### [low] Consent timestamps are written on the PHP clock

- evidence: `plugins/membership/public/router.php:95` `$fields['consent_at'] = date('Y-m-d H:i:s');`, stored in a column whose siblings default to `CURRENT_TIMESTAMP` (`membership_profiles.created_at`, `updated_at`, `install.sql`). `includes/helpers.php:200-207` documents that PHP and MySQL do not share a timezone on this host.
- failure case: `consent_at` is recorded up to the clock offset away from the `created_at`/`updated_at` values written by MySQL on the same row and the same request, so a consent audit reading the row sees the consent timestamped before or after the update that recorded it. No code currently *compares* `consent_at` against anything, which is why this is low rather than a live defect — but it is the timestamp a tenant would produce as evidence of consent, and it does not agree with the row's own audit columns.
- blast radius: `membership_profiles.consent_at` on every member who completes onboarding.
- fix: write it through the database clock, as `slate_db_now()` (`includes/helpers.php:210`) exists to do. Cheap and worth doing precisely because it is evidentiary.
- **verified**

---

## Where the code contradicts the browser audit

The brief's central hypothesis — every plugin re-implements people instead of sharing a core layer — **does not hold for these two plugins**, and the code should win here:

- Neither membership nor coaching has a person table. Both key off core `customers.id` and say so explicitly in their install headers (`plugins/membership/install.sql`, header comment: *"Identity is NOT duplicated: members are core `customers`"*; `plugins/coaching/uninstall.sql`: *"coaching never owns identity"*). Phone number is written back to core `customers` rather than shadowed (`plugins/membership/public/router.php:78`, `:138`).
- Compare booking, which *does* maintain its own `booking_customers` table.

What is genuinely duplicated between membership and coaching is not *identity* but **profile attributes** — DOB, gender, medical data — which is finding 6 above. That is a narrower and more tractable problem than "two person models", and it points at the profile layer rather than the identity layer. Reporting it as identity duplication would send the fix to the wrong place.

Both plugins are also clean on several axes worth recording so they are not re-investigated: complete admin authorization (13 of 13 screens), CSRF verified on both customer routers (`membership/public/router.php:41`, coaching equivalent), ownership checks on every destructive customer action (`membership/public/router.php:58` and `:152`; `coaching/customer/router.php:225`, `:1176`, and every `CoachingAPI` mutator takes `$customerId` and filters on it — e.g. `CoachingAPI.php:440-453`), complete uninstall scripts in both, all 21 tables tenant-scoped, and no setting written by two screens.

---

## Notes for the reconciler

1. **Finding 4 is a core gap, not a plugin gap, and it will recur in every remaining cluster.** There is no customer-deletion path and no `customer_deleted` hook. Booking, restaurant, shop, forms and clientdesk all hold person data; expect each to have the same dead end. This should be promoted to a cross-cutting finding in the final report rather than repeated five times.
2. **The PHP/MySQL clock split continues to appear** (finding 7 here, two findings in booking). Confirmed as a platform-wide pattern across three plugins so far.
3. **Attendance and quota are coupled.** Findings 1 and 3 share a root: there is no single definition of "an attended session". Fixing quota enforcement without first fixing the attendance predicate would enforce a quota against a count that includes future bookings — a worse failure than not enforcing it, because members would be blocked from booking by sessions they have not yet attended. Sequence matters if these are handed to implementation.
4. **Routing contract is followed unevenly.** `PublicRouter` sets `_route_path` (`src/Kernel/Http/PublicRouter.php:100`); booking consumes it, membership does not. Worth checking every plugin that registers a public route — the same silent-200 fall-through is likely elsewhere, and it is invisible to in-app navigation.
5. **Currency formatting is now three implementations** (booking's per-file closures, `MembershipAPI::money()` at `:142`, and whatever shop/restaurant use). Confirm in cluster 4 before writing it up as a cross-cutting duplication finding.
