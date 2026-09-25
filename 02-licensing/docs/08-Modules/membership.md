# 08 — Membership Module

**Status:** Draft · **Applies to:** Slate v3.x

## Purpose

Fixed-term and recurring memberships: plans, subscriptions, billing cycles, and
member benefits — layered on core Contacts and Payments, optionally integrating
Booking.

## Bounded context

**Commerce** ([02-Domain](../02-Domain/)).

## Consumes

| Service / capability | For |
|---|---|
| Identity | the member is a **Contact**; a membership record keyed by `contact_id` |
| Payments (`PaymentGateway`) | recurring/fixed-term billing via `Money` |
| `booking@1` (optional) | member-only or included sessions |
| Notifications | renewal/expiry reminders |

## Provides

- Capability `membership@1` (Coaching and other modules build on it).
- Membership blocks (signup, member portal).
- Events `membership.started`, `membership.renewed`, `membership.expired`.

## Owns

- `membership_plans`, `_memberships`, `_billing_cycles`, `_benefits` (slug-prefixed);
  every row keyed by `contact_id`.
- **Does NOT own:** the person (Contact), payments (gateway + ledger), booking
  data (consumed via `booking@1`).

## Routes & admin

- Public: signup + member portal (member-gated content/benefits).
- Admin: plans, members, billing, benefits.

## Integration events

- **Emits:** `membership.started`/`expired` → access changes, notifications, CRM
  activity.
- **Subscribes:** `payment.succeeded` (activate/renew by `ref`),
  `appointment.booked` (count included sessions), `contact.merged`.

## Why it composes cleanly now

Membership is the clearest example of the composition model: it **consumes**
Identity + Payments + `booking@1` and **provides** `membership@1` to Coaching —
all through capabilities and events, never another module's classes. This chain
(Identity → Membership → Coaching) is only tractable because identity is unified
([ADR-0006](../14-ADR/0006-unified-identity-contacts.md)) and modules talk through
contracts ([ADR-0005](../14-ADR/0005-events-and-contracts-for-modules.md)).

---

## Related

- [booking.md](booking.md) · [crm.md](crm.md) · [../07-API/payments.md](../07-API/payments.md) · [../14-ADR/0005-events-and-contracts-for-modules.md](../14-ADR/0005-events-and-contracts-for-modules.md)
