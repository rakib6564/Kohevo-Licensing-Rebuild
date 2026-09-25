# Booking+ — Phase 1 design spec

Companion plugin that extends Slate's core `booking` plugin to cover the
client's Level 1 requirements (appointment booking, reminders, payments,
per-service prep pages, HSR delay rules, Zoom links). Does **not**
modify booking core — attaches via public hooks and the `BookingAPI`
public methods.

Level 2 (the downloaded 3-month tracking app) is out of scope for this
phase and lives in a separate `coaching` plugin later.

---

## 1. What the core `booking` plugin already gives us

We do not need to build these — they are shipped and tested in booking
v0.5.1.

| Level 1 requirement | Where it lives in booking |
|---|---|
| Appointment types with fixed per-type duration | `booking_services` (Services admin) |
| Provider availability, day-of-week hours, breaks, date overrides | `booking_provider_hours`, `booking_provider_breaks`, `booking_date_overrides` |
| Auto-block duration based on chosen service | `BookingAPI::computeAvailableSlots()` — reads `duration_min` per service |
| Show only compatible slots to the client | Built into the `/book` widget |
| Client custom fields at end of booking (name/email/phone/etc) | `booking_custom_fields` per service |
| Multi-tier reminders (8 days / day before / 10 min) | Setting `booking.reminder_leads` (comma-list of minutes). We seed `11520,1440,10` on activate. Zero code. |
| Per-service email templates + placeholders | `booking_services.confirm_subject/confirm_body`, plus `BookingAPI::renderTemplate()` |
| SMS + WhatsApp reminders | `BookingAPI::sms()` / `whatsapp()` |
| Payments (full, deposit, free) | `booking_services.payment_mode` + `stripe-payment` plugin |
| Coupons, gift cards, refunds, invoices | Built into booking |
| Customer profiles, no-show tracking, tags | Built into booking |
| Cancel + reschedule self-service via `manage_token` | Built into booking |
| Follow-up email after session | Setting `booking.followup_enabled` + `booking.followup_delay_hours` |
| Cron reminder engine | `Booking::sendReminders()` on `frequent_cron` hook |

**Configuration-only tasks** (no code) that the therapist does in the
booking admin once:

1. Create 5 services: Discovery Call (20m/free), Full Nutrition Assessment
   (60m/full-payment), Emotional Serenity Hypnosis (120m/full), Regressive
   Spiritual Hypnosis (210–240m/deposit 1st hour), Body & Soul Program
   Follow-up (30 or 45m/monthly).
2. Create herself as a provider, set weekly hours + breaks.
3. Set `booking.reminder_leads` to `11520,1440,10` (we auto-seed this on
   plugin activate).
4. Write per-service confirmation templates.

---

## 2. What Booking+ adds (this plugin)

Everything in Level 1 that booking core does not cover.

### 2.1 Per-service extra config (new table `bookingplus_service_config`)

One row per booking service — extends `booking_services` without
modifying its schema.

| Column | Purpose |
|---|---|
| `service_id` (FK → booking_services.id) | Which service this config extends |
| `min_advance_days` | HSR requires 21+ days lead. Booking core has `min_advance_min` in minutes but no per-service day-level UI; we expose a days field and enforce via the `booking_can_book` filter. |
| `prereq_service_id` | HSR (and Nutrition Assessment) require the client to have completed a Discovery Call first. Points to Discovery Call's service id. |
| `prereq_message` | Message shown when prereq missing. Default: "This session requires a prior Discovery Call. Please book that first." |
| `prep_page_url` | URL to the preparation page on therapist's website (Hypnosis prep, Nutrition prep). Included in auto-response + 8-day reminder. |
| `whatsapp_url` | Per-service WhatsApp deeplink (or leave blank to use global default). |
| `auto_response_subject` | Subject of the immediate email sent after booking (see 2.3). |
| `auto_response_body` | HTML body of the immediate email. Placeholders: `{{name}} {{service}} {{when}} {{ref}} {{prep_url}} {{whatsapp_url}} {{payment_note}}`. |
| `reminder_8day_body` | Override the generic 8-day reminder body for this service. Includes the payment link if the service has a deposit/full-payment mode. |
| `reminder_1day_body` | Override the day-before reminder body. Includes Zoom link. |
| `reminder_10min_body` | Override the 10-min reminder body. |
| `zoom_mode` | `manual` (therapist pastes link into the appointment record), `fallback_message` (client is told "link will arrive by email"), or `api` (future — real Zoom API integration, Phase 1.5). |
| `zoom_join_url` | For `manual` mode: therapist pastes the recurring room URL here and all appointments of this service reuse it. |
| `hsr_redirect_service_id` | For HSR: if client tries to book but has no prereq, redirect to this service (Discovery Call) instead. Optional. |

### 2.2 Per-appointment extras (new table `bookingplus_appointment_meta`)

| Column | Purpose |
|---|---|
| `appointment_id` (FK → booking_appointments.id) | Owning appointment |
| `zoom_join_url` | Overrides the service default (for one-off links) |
| `zoom_link_sent_at` | Timestamp so we don't spam the link twice |
| `therapist_notified_at` | When the human-message email hit the therapist's inbox (2.5) |
| `therapist_replied_at` | Set when the therapist marks the message replied. Drives the 8-hour internal reminder. |
| `client_message` | The free-text "human message" the client wrote after providing name/phone/email (2.4) |

### 2.3 Post-booking auto-response (Hook: `booking_created`)

When `BookingAPI::createAppointment()` fires the `booking_created`
action, our listener sends the client an immediate follow-up email
using the service's `auto_response_subject`/`auto_response_body`
template. Placeholders:

- `{{prep_url}}` — from `prep_page_url`
- `{{whatsapp_url}}` — service or global default
- `{{payment_note}}` — auto-generated: "You will receive your payment
  link 8 days before the session" for paid services; "Free — no
  payment required" for Discovery Call; "First hour paid at booking,
  balance on the day of the session" for HSR deposit mode.

### 2.4 Booking gates (Hook: `booking_can_book` filter)

The filter fires on every online booking attempt. We check:

1. **Min-advance-days** — if `min_advance_days > 0` and the requested
   start is sooner, reject with `"This session requires X days'
   preparation. The next available slot is on {next_date}."` (We
   compute `next_date` by adding N days and finding the next available
   slot on the same provider.)

2. **Prereq service** — if `prereq_service_id` is set, look up the
   customer by email in `booking_customers` and check for a completed
   appointment with that service. If none, reject with the configured
   `prereq_message` and (optionally) redirect to
   `hsr_redirect_service_id`.

The gate runs only for `source='online'` (self-service). Admin walk-in
bookings bypass it — the therapist can override.

### 2.5 Human-message step + therapist notify

After the client hits Confirm, the standard booking flow calls
`sendConfirmation()`. Our companion plugin adds a **second step**: a
short optional text field ("Anything you'd like me to know?") shown
on the confirmation page. Submitting it:

- Stores the message in `bookingplus_appointment_meta.client_message`.
- Emails the therapist (`sendStaffNotification` overlay) with the
  message body prominently displayed.
- Stamps `therapist_notified_at`.

**Implementation note:** Rather than modifying the `/book` widget
templates directly, we add a filter/action so booking's confirmation
page can inject our textarea. The initial implementation uses a
simpler post-book redirect to `/plugins/bookingplus/public/message.php?ref=…`
so no changes to booking templates are required.

### 2.6 8-hour internal reminder (Hook: `frequent_cron`)

Our cron listener runs alongside booking's, looking for meta rows
where `therapist_notified_at IS NOT NULL AND therapist_replied_at IS
NULL AND therapist_notified_at < NOW() - INTERVAL 8 HOUR`. Sends the
therapist a nudge email once (marks a `nudge_sent_at` column so it
doesn't repeat).

### 2.7 Zoom link handling

- **Manual mode (MVP):** therapist pastes a persistent Zoom room URL
  into the service's `zoom_join_url`. Our day-before reminder embeds
  this link. If the appointment has an override in
  `bookingplus_appointment_meta.zoom_join_url`, that wins.
- **Fallback-message mode:** the day-before reminder says "Your
  connection link will arrive by email shortly" and the therapist gets
  a dashboard reminder to send it.
- **API mode:** deferred to Phase 1.5. Needs Zoom OAuth per-therapist
  and a small OAuth flow in `admin/settings.php`.

### 2.8 Per-service reminder bodies (Hook: `frequent_cron` — overlay)

Booking core's `sendReminders()` calls
`BookingAPI::sendReminder($appt, $when)` which uses a generic body.
We don't have a filter there in v0.5.1. **Two paths:**

- **Path A (recommended, this phase):** submit a tiny 3-line patch to
  booking core adding a `booking_reminder_body` filter. Then our
  companion overlays the per-service body.
- **Path B (no core patch):** we disable booking's generic reminder
  for services that have a Booking+ config (via a filter we add) and
  send our own via a parallel cron listener. Uses `reminders_sent`
  markers cooperatively so we don't double-send.

Path A is 3 lines in booking and clean; Path B avoids touching
booking at the cost of some cron logic. Decide before we code.

### 2.9 8-day payment link

Included inside the 8-day reminder body. For services with
`payment_mode = 'deposit'` or `'full'`, the placeholder
`{{payment_link}}` renders `SLATE_URL/book/manage?token={{manage_token}}`
which is the existing manage page — it already surfaces the Stripe
pay-intent flow. No new payment code needed.

---

## 3. Explicitly out of scope for Phase 1

Move to Phase 1.5 (2–3 more weeks after Phase 1 lands):

- **Per-slot type restrictions** ("Thursdays 12:00 = Discovery Call
  only"). Booking's `computeAvailableSlots()` has no filter today.
  Requires a small filter patch in booking + a
  `bookingplus_slot_restrictions` table. Fine architectural fit but
  non-trivial UI.
- **Real Zoom API integration.** Needs Zoom OAuth app + per-therapist
  token storage.
- **Interactive 7-day food-diary intake document** for Nutrition
  Assessment. Use the [forms](../plugins/forms) plugin — build the
  form once, link it from `prep_page_url`. Zero new code.
- **Automatic invoice PDF.** Booking already generates invoices; PDF
  export is a booking-core feature request.
- **Recording upload after session.** Media picker + a per-service
  "session recording" field on the appointment. Small addition, punted
  for scope.

---

## 4. Files this plugin ships

```
plugins/bookingplus/
├── plugin.json
├── install.sql              (2 tables: service_config, appointment_meta)
├── uninstall.sql
├── BookingPlus.php          (bootstrap: hook wiring, cron, seeds)
├── BookingPlusAPI.php       (public helpers other plugins can call)
├── admin/
│   ├── index.php            (dashboard: pending client messages, unresolved threads)
│   ├── services.php         (list services + inline config editor per row)
│   ├── service.php          (single-service editor)
│   ├── appointment.php      (extends booking's appointment view with Zoom + message pane)
│   └── settings.php         (global defaults: WhatsApp URL, Zoom mode default, HSR params)
├── public/
│   └── message.php          (post-booking human-message step)
└── assets/css/admin.css     (glass tokens — no new palette)
```

Permissions added:

- `bookingplus.manage_settings` — configure per-service extras + globals
- `bookingplus.reply_messages` — respond to the human-message thread

---

## 5. Cost + schedule estimate

- Spec sign-off + scaffolding: today (this session)
- HSR gate + auto-response + admin services page: ~3 dev-days
- Message thread + therapist notify + 8-hour nudge: ~2 dev-days
- Zoom manual mode + per-service reminder overlay: ~2 dev-days
- Admin polish + settings page + docs update: ~1 dev-day
- Total: **~8 dev-days for Phase 1**, shippable and useful.

Phase 1.5 (slot restrictions + Zoom API + Nutrition intake form): ~5
more dev-days.

Phase 2 (the coaching / 3-month program app) is a separate plugin
of comparable size to booking itself — realistically 4–6 weeks.
