# 08 — Booking Module

**Status:** Draft · **Applies to:** Slate v3.x (rebuilt on the spine)

## Purpose

Appointment booking: services, providers, resources, availability, and
appointments — with reminders and payments. A rebuild of today's mature `booking`
plugin onto core Identity, Payments, and the render stack.

## Bounded context

**Scheduling** ([02-Domain](../02-Domain/)).

## Consumes

| Service / capability | For |
|---|---|
| Identity | the customer is a **Contact**; a booking profile keyed by `contact_id` |
| Payments (`PaymentGateway`) | deposits/full payment, refunds — via `Money` |
| Notifications | email/SMS/WhatsApp reminders + follow-ups (channels, queued) |
| Blocks | the public `/book` widget as a Block |
| Data / migrations | its own tables, tenant-scoped |

## Provides

- Capability `booking@1` (so Membership/Coaching can offer bookable sessions).
- Booking blocks (booking widget, service list).
- Events `appointment.booked`, `appointment.canceled`, `appointment.rescheduled`.

## Owns

- `booking_services`, `_providers`, `_resources`, `_appointments`, `_availability`,
  `_addons`, `_service_fields`, … (slug-prefixed, **owned solely by this module**).
  `booking-plus` and `restaurant` own their *own* prefixes (`bookingplus_*`,
  `restaurant_*`) and reach booking only via `BookingAPI` — declared table ownership
  keeps it that way and makes a future prefix collision impossible
  ([ADR-0005](../14-ADR/0005-events-and-contracts-for-modules.md)).
- **Does NOT own:** the customer (Contact + booking profile on `contact_id`, never
  a `booking_customers` copy), payments (gateway + ledger), reminders' transport.

## Routes & admin

- Public: `/book` widget (multi-step, fast-book, custom fields, self-service
  cancel/reschedule).
- Admin: calendar, appointments, services, providers, resources, settings.

## Integration events

- **Emits:** `appointment.booked` → notification, CRM activity, search index.
- **Subscribes:** `payment.succeeded` (reconcile a deposit/booking by `ref`),
  `contact.merged` (move booking profile to the survivor).

## Notes on the rebuild

Availability's race-safety (`SELECT … FOR UPDATE`) is preserved. Money moves to
the `Money` type. Reminders become queued `NotificationChannel` sends rather than
synchronous email. Provider timezone becomes real slot computation over time
(a v3 nice-to-have).

---

## Related

- [membership.md](membership.md) · [notifications.md](notifications.md) · [../07-API/payments.md](../07-API/payments.md) · [../02-Domain/identity-contacts.md](../02-Domain/identity-contacts.md)
