# CON-1 survey — Booking and Booking+

**Read-only.** No plugin code was changed. Written 2 Sep 2026 against `develop` @ `5cdb3ec`.
Every claim carries `file:line`.

---

## 1. Does Booking expose an extension API?

**Yes. Eight extension points, and Booking+ already uses five of them.** This is the finding
that sizes CON-1, and it sizes it *down*: the seam exists and is load-bearing today.

The mechanism is the core hook registry, `src/Kernel/Event/Hook.php` — `addFilter` (`:38`),
`applyFilters` (`:47`), `addAction` (`:79`), `doAction` (`:84`), plus `removeFilter` (`:65`),
`removeAction` (`:101`), `hasFilter` (`:115`), `hasAction` (`:120`).

Booking fires these:

| point | kind | site |
|---|---|---|
| `booking_slot_allowed` | filter | `plugins/booking/BookingAPI.php:399` |
| `booking_can_book` | filter | `plugins/booking/BookingAPI.php:500` |
| `booking_created` | action | `plugins/booking/BookingAPI.php:692` |
| `booking_cancelled` | action | `plugins/booking/BookingAPI.php:798` |
| `booking_rescheduled` | action | `plugins/booking/BookingAPI.php:892` |
| `booking_paid` | action | `plugins/booking/BookingAPI.php:1125` |
| `booking_status_changed` | action | `plugins/booking/BookingAPI.php:1377` |
| `booking_reminder_body` | filter | `plugins/booking/BookingAPI.php:1544` |

`booking_can_book` is a real gate, not a notification — `BookingAPI.php:500-508`:

```php
$gate = Hook::applyFilters('booking_can_book', ['ok' => true], [
    'customer_id' => $customerId, 'service' => $service, 'starts_at' => $startsAt, …
]);
if (is_array($gate) && empty($gate['ok'])) {
    return ['ok' => false, 'error' => (string)($gate['error'] ?? '…')];
}
```

A listener returning `['ok' => false, 'error' => …]` refuses the booking, and the default
`['ok' => true]` means the hook is a no-op with nobody listening.

**What does NOT exist: any settings-field or admin-screen registration API.** `grep
applyFilters plugins/booking/admin/ plugins/booking/Booking.php` returns nothing. Booking
offers no way for a second plugin to add a field to an existing Booking screen, a column to
a Booking table, or a tab to Booking settings. **That single gap is why Booking+ has four
parallel admin screens instead of four sections inside Booking's own.** The behavioural seam
is good; the presentational seam is missing entirely.

---

## 2. Nav rows in the BOOKING group — 18, from two files

Both plugins register into the same `admin_nav_items` filter with `'group' => 'booking'`.

**Booking — 14 rows,** `plugins/booking/Booking.php:95-140`, registered at `:39`:

| # | slug | label | href | perm | order |
|---|---|---|---|---|---|
| 1 | `booking` | Booking | `admin/index.php` | *(none)* | 600 |
| 2 | `booking-calendar` | Calendar | `admin/calendar.php` | `booking.view` | 600 |
| 3 | `booking-appointments` | Appointments | `admin/appointments.php` | `booking.view` | 601 |
| 4 | `booking-customers` | Customers | `admin/customers.php` | `booking.view` | 601 |
| 5 | `booking-services` | Services | `admin/services.php` | `booking.manage_services` | 602 |
| 6 | `booking-providers` | Providers | `admin/providers.php` | `booking.manage_providers` | 603 |
| 7 | `booking-categories` | Categories | `admin/categories.php` | `booking.manage_services` | 604 |
| 8 | `booking-addons` | Add-ons | `admin/addons.php` | `booking.manage_services` | 605 |
| 9 | `booking-fields` | Custom fields | `admin/fields.php` | `booking.manage_services` | 606 |
| 10 | `booking-locations` | Locations | `admin/locations.php` | `booking.manage_resources` | 607 |
| 11 | `booking-resources` | Resources | `admin/resources.php` | `booking.manage_resources` | 608 |
| 12 | `booking-coupons` | Coupons | `admin/coupons.php` | `booking.manage_payments` | 610 |
| 13 | `booking-giftcards` | Gift cards | `admin/giftcards.php` | `booking.manage_payments` | 611 |
| 14 | `booking-settings` | Booking settings | `admin/settings.php` | `booking.manage_settings` | 612 |

Whole group gated at `Booking.php:96` — `if (!Auth::can('booking.view') && !Auth::isSuperAdmin()) return $items;`

**Booking+ — 4 rows,** `plugins/booking-plus/BookingPlus.php:66-92`, registered at `:29`:

| # | slug | label | href | perm | order |
|---|---|---|---|---|---|
| 15 | `bookingplus-messages` | Booking+ Messages | `admin/index.php` | `bookingplus.reply_messages` | 690 |
| 16 | `bookingplus-services` | Booking+ Services | `admin/services.php` | `bookingplus.manage_settings` | 691 |
| 17 | `bookingplus-restrictions` | Booking+ Reserved slots | `admin/restrictions.php` | `bookingplus.manage_settings` | 691 |
| 18 | `bookingplus-settings` | Booking+ Settings | `admin/settings.php` | `bookingplus.manage_settings` | 692 |

Gated at `:68-73` on either Booking+ permission or super-admin.

**Removing four rows is one edit to one method** — `BookingPlus.php:66-92`. Nothing else
reads those slugs; `grep bookingplus-` across the tree finds them only where they are
defined. The rows are a `$items[]` append, so deleting them cannot break Booking's own 14.

---

## 3. What Booking+ actually adds

Nine capabilities. **Four ride Booking's hooks; five have no seam and exist only as
Booking+'s own screens and tables.** That split is the CON-1 map.

| # | capability | implemented | configured by | seam |
|---|---|---|---|---|
| 1 | prep pages | `BookingPlus.php:248`, `:325` (`prep_url` into templates) | Booking+ Services (`admin/service.php`) | `booking_reminder_body` filter |
| 2 | WhatsApp links | `BookingPlus.php:232`, `:304`; global fallback `BookingPlusAPI::globalWhatsappUrl()` | Booking+ Services, Booking+ Settings | `booking_reminder_body` filter |
| 3 | `min_advance_days` | `BookingPlus.php:142` | Booking+ Services | `booking_can_book` filter (`:34`) |
| 4 | prerequisite gate | `BookingPlus.php:156` (`prereq_service_id`) | Booking+ Services | `booking_can_book` filter (`:34`) |
| 5 | reserved slots | `BookingPlus.php:344-360`, table `bookingplus_slot_restrictions` | Booking+ Reserved slots (`admin/restrictions.php`) | `booking_slot_allowed` filter (`:45`) |
| 6 | auto-response | `BookingPlus.php:212-254` (`auto_response_subject`/`_body`) | Booking+ Services | `booking_created` action (`:35`) |
| 7 | internal nudge | `BookingPlus.php:372-395` cron sweep | Booking+ Settings (`nudge_hours`) | `frequent_cron` action (`:36`) |
| 8 | Zoom handling | `BookingPlus.php:315` (`zoom_mode`) | Booking+ Services | `booking_reminder_body` filter — **`api` mode is inert, see audit M-6** |
| 9 | human-message step | `public/message.php`, table `bookingplus_appointment_meta` | Booking+ Messages (`admin/index.php`) | **none** — standalone public page |

Also registered: `admin_dashboard_widgets` (`:30`), and the multi-tier reminder cadence seed
`maybeSeedReminderLeads()` (`:56-61`, called from `boot()` at `:24`) — which is audit finding
M-4, the third writer of `booking.reminder_leads`.

---

## 4. The two Services screens

**Two tables, not one.** Booking owns `booking_services`; Booking+ owns
`bookingplus_service_config`, one row per service carrying the extras.

The key is `service_id`, with a per-tenant uniqueness constraint —
`plugins/booking-plus/install.sql`:

```sql
`service_id`  INT UNSIGNED NOT NULL,
UNIQUE KEY `tenant_service` (`tenant_id`, `service_id`),
```

**There is no foreign key.** `grep -c "FOREIGN KEY" plugins/booking-plus/install.sql` returns
**0**. The link is a bare integer, so deleting a Booking service leaves an orphan config row,
and nothing in the schema prevents a `service_id` that never existed. Reads go through
`BookingPlusAPI::getServiceConfig()` (`BookingPlusAPI.php:41-49`), which is tenant-scoped and
falls back to `defaultServiceConfig()` when no row exists — so a missing row degrades to
defaults rather than erroring.

**"Edit core services" is a plain cross-link, carrying no state** —
`plugins/booking-plus/admin/services.php:45`:

```php
<a href="<?= e(plugin_url('booking', 'admin/services.php')) ?>" class="btn btn-ghost">Edit core services</a>
```

No query string, no service id, no return path. It is `plugin_url()` with a bare relative
path. Clicking it loses whatever service you were looking at — which is the clearest
single symptom of the missing presentational seam in §1.

---

## 4b. How the current tenant is resolved, and what assumes there is only one

### The mechanism

`current_tenant_id()`, `includes/helpers.php:20-31`. Three sources, in order:

1. `$GLOBALS['SLATE_TENANT_OVERRIDE']` (`:22-24`) — set only by `with_tenant()` (`:38-50`), for CLI and cron sweeps.
2. `$_SESSION['slate_override_tenant']` (`:26-28`) — super-admin impersonation, and only when `Auth::isSuperAdmin()`.
3. **`TENANT_ID`** (`:30`) — the constant from `.env`, via `config.php:46`.

`slate_tenant_id()` (`:74-76`) is the CORE-1 accessor and currently delegates to it unchanged;
`Slate\Tenancy\TenantContext::id()` (`src/Tenancy/TenantContext.php:45-54`) does the same.

**There is no host-based resolution.** No `HTTP_HOST` lookup in `src/Tenancy/` or `helpers.php`,
and the `tenants` table has no domain column — `db/schema.sql:13` says so outright: *"tenant
install only ever has one row."* So today one deployment serves one tenant, and path 3 answers
every web request. **Everything below is inert now and becomes live the moment a second tenant
shares a database.**

### Genuine single-tenant assumptions — these leak

**1. The reminder cron sweeps every tenant and mails them as this one.** The worst of the set.

`plugins/booking/Booking.php:358-368`:

```sql
FROM booking_appointments a
JOIN booking_services  s ON s.id = a.service_id
JOIN booking_providers p ON p.id = a.provider_id
WHERE a.status = 'confirmed' AND a.starts_at > NOW() …
```

No `tenant_id`, anywhere in the statement. And `cron.php:43` fires `Hook::doAction('frequent_cron')`
with **no `with_tenant()` wrapper**, so `current_tenant_id()` falls to `TENANT_ID`. The sweep
therefore selects appointments belonging to *every* tenant, while `BookingAPI::sendReminder()`
resolves its templates, site name and SMTP through `Database::setting()` — which is scoped to
tenant 1. Tenant 2's customers receive tenant 1's branded email from tenant 1's mail server. It
then stamps `reminders_sent` on tenant 2's rows (`:371-374`), so a correctly-scoped sweep would
later skip them as already sent.

**2. The follow-up sweep, identically.** `Booking.php:381-390`, same missing filter, same cron,
same `followup_sent` stamp at `:393`.

**3. Provider name and email fetched by bare id.** `BookingAPI.php:732`:
`SELECT name, email FROM booking_providers WHERE id = ?`. `$providerId` arrives from the request.
The only thing that validates it is the association check at `:461-465`, which reads
`booking_provider_services` — a table with **no `tenant_id` column**. Two more of the same shape
at `public/router.php:734` and `:780`, both taking `provider_id` straight from `$args`.

**4. Customer email by bare id.** `plugins/booking-plus/BookingPlus.php:197`:
`SELECT email FROM customers WHERE id = ?`, with `$cid` read from the booking payload
(`emailFromContext()`), not from a tenant-scoped lookup.

**5. Two join tables carry no tenant at all** — `booking_provider_services` and
`booking_service_resources` (`plugins/booking/install.sql`). They are the reason #3 cannot be
fixed by patching the query alone: there is nothing in those tables to filter on. This is audit
finding L-3, promoted from "latent" to "load-bearing" by #3.

### Flagged but safe, and why

Recorded so a future pass does not re-litigate them.

- `admin/services.php:67` — `prereq_service_id` comes from the tenant-scoped config row.
- `Booking.php:260`, `:311` — `customer_id` comes from `Auth::customerId()`; customer ids are unique across tenants.
- `admin/appointment.php:48`, `public/router.php:126` — the id was tenant-verified, or created, moments earlier in the same request.
- `BookingPlus.php:228` — fires from `booking_created` with the id this tenant just wrote.
- `admin/providers.php:434` — counts keyed on a `provider_id` taken from an already-scoped list.
- `BookingAPI.php:1335` — **safe by adjacency; do not separate from the tenant-scoped `SELECT`
  at `:1332`.** The statement is `UPDATE booking_customers … WHERE id = ?` and carries no tenant
  filter of its own; it is correct only because the id it uses was fetched three lines earlier by
  `SELECT id FROM booking_customers WHERE tenant_id = ? AND email = ?`. That is a property of the
  *sequence*, not of either statement, so it is invisible to the guard and to a reviewer reading
  a diff. **CON-1 moves code.** Extracting the update into a helper, reordering the branch, or
  lifting the `SELECT` into a caller all silently convert this into a cross-tenant write. If it
  is touched at all, give the `UPDATE` its own `tenant_id` predicate rather than preserving the
  adjacency.

**One is a genuine anti-drift false positive:** `BookingAPI.php:1404` *does* filter `tenant_id` —
`"UPDATE booking_customers SET " . implode(', ', $sets) . " WHERE tenant_id = ? AND email = ?"`,
bound from `:1401`. The guard misses it because the statement is assembled by concatenation.
Worth knowing before someone "fixes" a query that is already correct.

### What this means for CON-1

Consolidation touches exactly the code that has these gaps. Both cron sweeps, the provider
lookups and the join tables are Booking's, not Booking+'s — so **merging Booking+ into Booking
neither causes nor cures this**. The risk is subtler: moving code makes each of these look
freshly reviewed when it has not been.

The cheap protection is the CORE-1 ratchet. All sixteen sites are already in
`bin/anti-drift-baseline.txt`, so a consolidation that carries them across unchanged keeps the
TENANT count flat and passes; one that introduces a seventeenth fails the build. Fixing the two
cron sweeps first would also drop the backlog by two and remove the only entries on this list
that mail real customers.

---

## 5. What happens today if Booking+ is deactivated

**Established, not reasoned: clean degradation, with one exception.**

The coupling is strictly one-directional. `grep -rn "BookingPlus\|bookingplus" plugins/booking/`
returns **nothing** — Booking never names Booking+, never reads `bookingplus_*` tables, and
has no conditional on its presence. Booking+ depends on Booking, not the reverse.

So on deactivation:

- **No fatal error.** `PluginLoader` only boots active plugins, so Booking+'s `boot()` never
  runs and none of its seven `addFilter`/`addAction` registrations (`BookingPlus.php:29-45`)
  are made. Every hook falls back to its default: `booking_can_book` returns `['ok' => true]`,
  `booking_slot_allowed` returns `true`, `booking_reminder_body` returns the unmodified body.
- **The four nav rows disappear**, because `addAdminNav` is never registered.
- **Silent feature loss, by design.** Bookings that a Booking+ rule previously blocked now
  succeed; reserved slots stop being reserved; auto-responses and nudges stop sending.
  Nothing announces this.
- **Existing bookings still render.** They are ordinary `booking_appointments` rows and
  Booking's screens never read `bookingplus_appointment_meta`. The Booking+ extras attached
  to them — client messages, Zoom URLs, reply timestamps — become unreachable but are not
  deleted; the tables survive (`uninstall.sql` only runs on uninstall, not deactivation).

**The exception, and it is a real one.** `plugins/booking-plus/public/message.php` has **no
active-plugin guard**. It requires `config.php` (`:16`), `BookingPlusAPI.php` (`:17`) and
`BookingAPI.php` (`:18`) directly, so it remains a working public endpoint after
deactivation — still resolving tokens, still writing `client_message` rows into a plugin the
operator believes is off. Compare `plugins/booking/public/pay-intent.php:36`, which does
guard: `if (!PluginLoader::isActive('booking')) { bpi_out(503, …); }`.

That is a one-line divergence between two sibling public entry points, and the same
diverged-copy shape as audit H-3.

---

## 6. Test harness for bookings

**There is one booking test, and it does not test booking.**

`tests/integration/BookingBlockInlineTest.php` — 4 cases, 19 assertions — covers the
**content-builder block** that renders the service catalogue on a CMS page. Its own docblock
is explicit about the boundary (`:8-14`): *"the block inlines STEP ONE — the catalogue …
each card links to /book, where the wizard is untouched."*

So: nothing tests `createAppointment()`, the availability calculation, capacity reservation,
the payment paths, coupons, gift cards, reminders, or either hook that Booking+ gates
through. `grep -rln "BookingAPI\|booking_appointments" tests/` returns that one file.

**CON-1 requires a golden test proven fallible, and there is nothing to build on.** The
smallest real one — and the one the first slice needs — is a `booking_can_book` gate test:

- seed a service and provider in the test database
- assert `createAppointment()` succeeds with no listener registered
- register a listener returning `['ok' => false, 'error' => 'nope']` via `Hook::addFilter`
- assert the same call now fails with that error, and that **no row was written**
- `Hook::reset()` (`src/Kernel/Event/Hook.php:126`) between cases

That is ~40 lines, needs no HTTP, and is fallible in the way that matters: delete the
`applyFilters` at `BookingAPI.php:500` and it goes red. It pins the exact contract any
consolidation must preserve.

---

## Proposed first slice

**Add a sub-section key to `admin_nav_items`, and have Booking+ declare its four rows as a
sub-section of Booking's group.**

An earlier draft of this section proposed the opposite — moving the four rows into Booking's
`addAdminNav()` behind `PluginLoader::isActive('booking-plus')`. **That was wrong**, and wrong
against a property this same document established two sections earlier: §5 shows by grep that
Booking never names Booking+, and that one-directional coupling is what makes §5's clean
degradation true at all. Putting an `isActive('booking-plus')` call inside Booking would have
inverted the dependency to save four lines, and traded a stated module-contract property for a
cosmetic tidy. It would also have consolidated *nothing* — the same rows in a different file.

### The change

The nav renderer already groups by a flat `group` key — `admin/partials/header.php:64`, labels
resolved by `slate_admin_group_label()` at `:227-241`, mirrored for mobile at
`admin/partials/footer.php:66-88`. Items with the same `group` render under one uppercase
heading. There is no sub-heading concept, which is why Booking+'s four rows currently sit as
peers of Booking's fourteen, distinguishable only by the words "Booking+" typed into each label
(`BookingPlus.php:75-88`).

Add an optional `section` key alongside `group`. Core renders it as a labelled sub-heading
within the group; items without one render exactly as today, so all fourteen Booking rows and
every other plugin's nav are untouched. Booking+ then keeps its own `addAdminNav()` and declares:

```php
'group' => 'booking', 'section' => 'Booking+',
```

The four labels lose their "Booking+ " prefix, because the sub-heading now carries it.

### Why this is the right shape

- **Booking never learns Booking+ exists.** The coupling stays one-directional and §5 stays true.
- **It builds the seam §1 says is missing.** §1's finding is that Booking's *behavioural* seam is
  good and its *presentational* seam is absent. This adds a presentational extension point — and
  adds it in **core**, so it is available to every plugin, not a private arrangement between two.
  Shop, Studio and Clientdesk all register nav rows and all have the same latent need.
- **Lowest possible stakes.** Nav rows are pure presentation: no schema, no hook contract, no
  behaviour. Nothing in §3's nine capabilities routes through `addAdminNav()`.
- **Revertible in one commit.** Remove the `section` handling from the two renderers and the one
  key from Booking+'s array. No migration, nothing to undo in the database.
- **It is a real answer to "one table or two".** §4's cross-link problem — "Edit core services"
  at `admin/services.php:45` throwing away which service you were looking at — is the same
  missing seam one level deeper. Proving the pattern on nav is what makes the Services screens
  approachable without guessing.

### Sequence

1. **The `booking_can_book` gate test from §6, first.** Not because this slice needs it — it
   touches no behaviour — but because the next slice will, and a test proven fallible against
   today's code is worth more than one written after the refactor it exists to protect.
2. **The missing `isActive` guard on `booking-plus/public/message.php`** (§5). Unrelated to
   consolidation, one line, and it should not wait behind an epic.
3. **This slice.**
4. **The two cron sweeps** (§4b). Note the real question there is a core one, not Booking's:
   `cron.php:43` fires `frequent_cron` with no tenant context at all, so *every* plugin's cron
   listener inherits the same assumption. Whether `cron.php` should iterate tenants via
   `with_tenant()` is a platform decision that outlives CON-1, and patching Booking's two
   queries without settling it just moves the problem to the next plugin that adds a sweep.
