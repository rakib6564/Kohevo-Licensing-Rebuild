# Booking

Full appointment booking for Slate — services, providers, scheduling,
payments, notifications and a public `/book` widget customers walk
through to pick a time.

- **Version:** 0.5.1
- Confirmation email on creation; configurable-lead reminders + post-
  visit follow-ups sent by cron; optional SMS/WhatsApp via Twilio.

## What it does

### Services & catalogue
- Services with categories, per-service duration, slot interval,
  buffer-before / buffer-after, capacity (group bookings), min-advance
  and max-advance windows, colour, online flag and sort order.
- Add-ons (extra time / price), custom intake fields (text, textarea,
  select, checkbox, file upload), and per-service email template
  overrides.
- Per-staff price/duration overrides and group price tiers
  (`price_tiers_json`).

### Providers & scheduling
- Providers (name, email, phone, timezone, bio, colour) linked
  many-to-many to services.
- Weekly recurring hours, breaks, and date-specific overrides.
- Slot engine respecting hours, buffers, capacity, advance windows and
  existing bookings. Race-safe creation via `SELECT … FOR UPDATE` in a
  transaction.

### Locations & resources
- Locations (incl. online/meeting-URL) and bookable resources/rooms
  with conflict prevention.

### Public widget (`/book`)
- Multi-step flow (service → provider → date+slot → confirm → success),
  server-side state via query params.
- Group size, add-ons, custom fields (incl. file upload), and optional
  weekly recurring bookings.
- Fast-book a service directly: `/book/<service-slug>`.
- Self-service manage link (`/book/manage?token=…`) to cancel or
  reschedule a booking.
- `?embed=1` strips the canvas/footer for iframe embeds.

### Payments & billing
- Payment modes per service: free, full, deposit (fixed or %), or
  pay-onsite. Tax rate per service.
- Coupons and gift cards; discounts recorded on the appointment.
- Stripe checkout via the `stripe-payment` plugin (hosted Checkout).
  Paid amounts are taken from Stripe's reported `amount_total`.
- Refunds (full) and printable invoices from the appointment detail.

### Admin
- Overview dashboard (today / next 7 days / active services).
- **Calendar** — month grid coloured by provider, with provider filter
  and prev/next/today navigation (`admin/calendar.php`).
- Appointments list with provider/status/date filters + detail view.
- Manual / walk-in booking (`admin/new.php`).
- CRUD for services, categories, add-ons, custom fields, providers,
  locations, resources, coupons, gift cards, customers; settings.
- Customer profiles keyed by email (guests included) with no-show /
  loyalty counters and tags.

### Notifications
- Confirmation, staff-notice, configurable-lead reminders, cancel /
  reschedule, and post-visit follow-up emails.
- Optional SMS + WhatsApp via Twilio (per settings).

## Public URLs

| URL | What |
|---|---|
| `/book` | Step 1 — pick service |
| `/book/<service-slug>` | Fast-book: preselect a service |
| `/book?service=N` | Step 2 — pick provider |
| `/book?service=N&provider=M` | Step 3 — pick date + slot |
| `/book?service=N&provider=M&date=…&slot=…` | Step 4 — confirm form |
| `/book/manage?token=…` | Self-service cancel / reschedule |
| `/book/pay/done?token=…&session_id=…` | Stripe checkout return |
| `?embed=1` (with any params) | Strips canvas + footer for iframes |

## Setup checklist

1. Create services (`/admin/booking/services`) — set duration,
   capacity, payment mode, etc.
2. Optionally group them into categories and add add-ons / custom
   fields.
3. Create providers (`/admin/booking/providers`); link services, set
   weekly hours, breaks and any date overrides.
4. (Optional) Configure locations/resources, coupons/gift cards, and
   SMS/WhatsApp under booking settings.
5. Wire core cron — see below.
6. Open `/book` and walk through it; the appointment lands in the admin
   inbox/calendar and the customer gets a confirmation email.

## Cron (reminders + follow-ups)

Booking listens on the `frequent_cron` action. Hit Slate's generic cron
entry point every ~5 minutes:

```cron
*/5 * * * * curl -fsS 'https://yoursite/cron.php?key=YOUR_CRON_SECRET' > /dev/null
```

Reminder dispatch is idempotent: each fired lead is recorded in
`reminders_sent` (CSV of lead-minutes) and follow-ups flip
`followup_sent`, so running the cron twice won't double-send.

## Hooks

| Hook | Type | Args |
|---|---|---|
| `booking_created` | action | `(int $appointmentId, int $serviceId, int $providerId)` |
| `booking_status_changed` | action | `(int $appointmentId, string $newStatus)` |
| `booking_cancelled` | action | `(int $appointmentId)` |
| `booking_paid` | action | `(int $appointmentId, int $amountCents)` |

## Permissions

| Key | Allows |
|---|---|
| `booking.view` | Dashboard, calendar, appointments list + details |
| `booking.manage_services` | CRUD services, categories, add-ons, fields |
| `booking.manage_providers` | CRUD providers + hours, breaks, overrides |
| `booking.manage_appointments` | Create / cancel / reschedule / complete / no-show |
| `booking.manage_resources` | Manage locations + resources/rooms |
| `booking.manage_settings` | Configure booking settings |
| `booking.manage_payments` | Coupons, gift cards, refunds + invoices |

## Schema

Base tables come from `install.sql` / `BookingAPI::ensureSchema()`.
Additive changes ship as `migrations/*.sql` (new tables) plus
version-gated PHP in `Booking::runMigrations()` (column/index additions),
so existing installs self-heal. Core tables include:

- `booking_services`, `booking_categories`, `booking_service_addons`,
  `booking_custom_fields`
- `booking_providers`, `booking_provider_services`,
  `booking_provider_hours`, `booking_provider_breaks`,
  `booking_date_overrides`
- `booking_locations`, `booking_resources`, `booking_service_resources`
- `booking_appointments`, `booking_customers`
- `booking_coupons`, `booking_gift_cards`

All tenant-scoped via `tenant_id`.

## Still planned

- Native calendar drag-to-reschedule (the calendar is currently
  read/navigate + click-through).
- Google Calendar / iCal sync.
- Multi-timezone slot computation (currently server-local).
- Waitlist and membership plans.
