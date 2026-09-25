# Slate — Duplication & UI/UX Audit

> ## Corrections — read first
>
> Three code-level audits have run since this document was written. Two of its conclusions were **disproved by the code**. Do not act on them as written.
>
> **1. "Six people directories" is the wrong layer.** Membership and coaching key off core `customers` and say so in their install headers — they do *not* duplicate identity. What they duplicate is **profile attributes**. Booking, shop, restaurant and clientdesk *do* hold their own person-bearing tables, and booking additionally snapshots name/email/phone onto every appointment, so the real picture is mixed. There is also a half-built identity spine already in the tree (`contacts`, `contact_emails`, `contact_phones`, `identities` via `db/migrations/0002_identity_core.php`, dual-written by `ContactSeeder`, reads still on legacy tables). The consolidation work is profile-level and cutover-level — not "build a person record".
>
> **2. The two reminder fields write the same key.** Both Booking and Booking+ write `booking.reminder_leads`, and runtime reads only that key, so it is deterministic last-write-wins — not conflicting values. The problem is split admin ownership, which is a smaller fix.
>
> **The sharper pattern the code revealed:** plugins duplicate **access paths, not objects**, and the copies drift — five `manage_token` lookups with one missing its tenant filter, three site lookups with one missing it, seven `APP_SECRET` consumers with three wrong. Read everything below through that lens: where this document says "two implementations", the code usually says "one correct implementation and one stale copy of it".
>
> **Also:** the code audits reached things no browser pass could — unauthenticated maintenance scripts under `plugins/studio/tools/`, an empty-`APP_SECRET` HMAC weakness, missing tenant filters on two public lookups, and a PHP-vs-MySQL clock mismatch in booking reminders. Those outrank everything in this document.
>
> **The live plan is `claude/slate-issue-queue.md`.** This document is kept for its browser-level evidence, not its conclusions.

---

**Scope:** admin dashboard (`/slate/admin`) and customer portal (`/slate/customer`, `/slate/member`, `/slate/coaching`) on `greenlightinduction.rakibhasaan.com`, walked screen by screen 31 Aug 2026.
**Lens:** compared against how WordPress and Wix solve the same problems.
**Note:** the tenant's content is dummy data. Findings below are about *structure*, not the sample records — except where marked `[data]`.

---

## The root cause, in one sentence

WordPress has **one** users table, **one** media library, **one** settings screen, and plugins hang submenus off those shared objects. Wix has **one** contacts CRM every app writes into. Slate spreads the same job across plugins — so the admin *feels* duplicated in a way WordPress does not.

*(Corrected: the code shows this is less true of the data model than of the access paths and the admin surfaces. See the corrections block above.)*

---

## P0 — Same object, many owners

### 1. Six people directories ⚠️ superseded — see corrections

| Screen | Owner | Holds |
|---|---|---|
| Users | core | admin logins + roles |
| Booking → Customers | booking | name, email, bookings count, loyalty pts, tags |
| Forms → Contacts | forms | name, email, phone, submissions count |
| Membership → Members | membership | plan, status, member profile |
| Coaching → Program clients | coaching | enrolment, profile completeness |
| Restaurant → Customers | restaurant | name, phone, email |

Six *screens* is accurate and still a real UX problem. Six *directories* is not — membership and coaching are views over core `customers`. Observed `[data]`:

- `vai6564@gmail.com` — "Rakib hasan" (19 bookings) in Booking, "Rakib" in Members
- `rakib0493@gmail.com` — "test from Rakib hasan test" (25 bookings) in Booking, a contact with 6 submissions in Forms, "rk" in Members

An admin still cannot answer "what is this client's history" from one screen. That part stands.

### 2. Three client profiles asking the same questions ✅ confirmed by code

| Field | Account (`customer/profile.php`) | Membership → Profile | Coaching → Profile |
|---|---|---|---|
| Name / phone | ✓ | phone + photo | — |
| Gender | — | ✓ | ✓ |
| Date of birth | — | ✓ | ✓ |
| Medical | — | "Medical conditions" | "Pathologies (past/current)", "Ongoing care" |
| Allergies | — | "Allergies" | "Intolerances" |
| Height / weight / BMI / BMR | — | — | ✓ |
| Emergency contact, skill level | — | ✓ | — |

Gender, DOB, medical and allergy data are collected **twice, in different words, into different tables** (`membership_profiles`, `coaching_profile`). The code audit went further: **there is no deletion path for any of it** — no core customer-erasure route and no `customer_deleted` hook — so allergies, pathologies and body photographs cannot be removed through the product at all.

### 3. Services configured on two screens ✅ confirmed

Booking → Services and Booking+ → Services list the **identical 7 services** with different attribute sets. Booking+ ships an "Edit core services" link — the UI itself admitting you must bounce between two menu sections to configure one object.

### 4. Booking rules split across three plugins ✅ confirmed

"Why can't this client book?" requires checking **Booking** (widget fields, coupons, gift cards, recurring), **Booking+** (`min_advance_days`, prerequisite service gate, reserved slots) and **Membership** (require active membership, require completed profile, insurance-required courses). Three screens, three plugins, one question.

### 5. Reminder timing configured in three places ⚠️ partly superseded

- Booking settings → "Reminder lead times (minutes, comma-separated)"
- Booking+ settings → "Lead times (minutes, comma-separated)", labelled *"(shared with Booking)"*
- Membership settings → "Reminder lead days (comma-separated)"

The first two write the **same key** and runtime is deterministic — split ownership, not conflicting values. The third is a genuinely separate concept in different units.

---

## P1 — Duplicated surfaces

### 6. Four views of the same appointments (admin)

Dashboard "Booking" card → Booking (Today / Next 7 days) → Calendar → Appointments. Four screens, one dataset.

### 7. Four inboxes (admin)

Forms → Submissions, Booking+ → Messages, Coaching → Client chat, and core Notifications. Four places a message from a client can land, none aware of the others.

### 8. Three "upcoming appointments" lists (client)

Portal Home → "Upcoming appointments", Membership → Schedule, and the Coaching program's booking integration.

### 9. Three dashboards nested inside each other (client)

Portal Home summarises Membership and Program; both then have their own Home with their own greeting and stat tiles. A client hits three "home" screens to reach a food diary.

### 10. Two i18n mechanisms in one app

The membership area uses its own `?lang=fr` / `?lang=en` text links. The rest of the portal uses multilang-translate's `?mlt_lang=fr` flag dropdown. Two switchers, two visual treatments, two code paths.

Related: **the customer portal is bilingual, the admin is not** — and the admin is what Stéphanie reads.

### 11. Two logins of unequal quality

| | `/slate/admin` | `/slate/customer` |
|---|---|---|
| Copy | "Log in to your dashboard" | "Sign in to manage your account" |
| Show password | ✗ | ✓ |
| Forgot password | ✗ | ✓ |
| Register | ✗ | ✓ (3-step wizard) |
| EN/FR switcher | ✗ | ✓ |

`/slate/member` is an alias of `/slate/customer/`, not a third login.

### 12. Two theme systems on one screen

Site Settings carries **"Site theme"** (5 options) *and* **"Theme preset"** (6 options), with the page stating *"These two will merge in a later phase."*

### 13. Two site names, two branding surfaces

Settings → General → "Site name" and Site Settings → Branding → "Site name".

### 14. Three category systems

Content → Taxonomies (Categories/Tags), Booking → Service categories, Restaurant → Categories.

### 15. Two help pages

`admin/help.php` and `restaurant/admin/help.php`.

---

## P2 — Navigation & IA

The sidebar groups are mislabelled, which makes the product feel more duplicated than it is:

- **PLUGINS** contains Pages, Posts, Post Types, Taxonomies, Menus, Site Settings — that is *Content*
- **CONTENT** contains Forms, React Sites, Coaching, Translations — Coaching is not content
- **SETTINGS** contains exactly one item (AI Access)
- The real plugin manager lives under **SYSTEM → Plugins** — two different things called "Plugins"
- **BOOKING** is 15 rows, four of them Booking+ duplicating Booking's own rows
- **RESTAURANT** — "Restaurant" and "Orders" point at the *same URL* (`restaurant/admin/index.php`)

**Fix (WordPress model):** top-level items are nouns the user thinks about — Content, Media, People, Bookings, Membership, Program, Commerce, Appearance, Settings, System. Plugins add **submenus under those**, never new top-level groups.

The header search ("Search or jump to…") is the best thing in the current admin and should become the primary way to reach any of the ~60 screens; the breadcrumb shows one level and should show the real path.

---

## Smaller UI/UX issues found

- **Attendance marked before it happens** — a 15:00 session shows "✓ Present" at 11:20am ✅ *cause found: no `starts_at <= NOW()` predicate; `$present` set on `confirmed`*
- **"Course progress" shows the member enrolled in all 7 services**, including ones with 0 sessions ✅ *cause found: returns every active service, locks only on missing insurance*
- **Session quota vs time-based plans mixed** — "Sessions used 2/10" on a 90-day Season pass. *The code audit went further: `session_quota` is sold but never enforced.*
- **Currency mixing** — Season pass in USD next to bookings in EUR; services list mixes EUR and USD
- **Dead-end account screen** — "Email changes aren't supported yet — contact support" and "To change your password, sign out and use the reset link". Both should be inline.
- **Emotion logging is trapped inside meal logging** — all 10 mood chips are labelled "Log a meal feeling X"
- **The coaching Home repeats itself** — hydration appears 3× on one screen (header stat, progress ring, water card), meals 3×, goals 2×
- **"Everything else"** is a catch-all bucket of 6 features, and its "My goals" duplicates the section nav's "Goals"
- **`/slate/member/profile` silently renders Home** ✅ *cause found: membership's own router maps every unknown `?view=` to `home`; core `PublicRouter` 404s correctly*
- **Duplicate media files** `[data]` — `energy-hero.jpg`, `electric-hero.jpg`, `beachside-hideaway.jpg` and four `wailea` lots each exist twice, one Used one Unused; 20 of 35 files unused
- **Live public booking widget shows leftovers** `[data]` — a "HAIRCUT" category with "Boxe 100% Féminin" and "waterxfight" in USD sits under the real hypnosis services at `/book`
- **404 page is branded "COMPANY B STUDIO"** while the admin is branded Solaya

---

## What this pass could not cover

- Code-level duplication, security, tenancy — covered by the three later audits
- Mobile/responsive behaviour beyond the default pane width
- Accessibility (the admin renders two `Primary navigation` landmarks with the same accessible name)
- React Site Bridge, Translations, AI Access and Restaurant admin screens in depth
