# 08 — CRM Module (Future)

**Status:** Draft · **Applies to:** Slate v3.x

## Purpose

Manage relationships: a contact database with pipelines, deals, activities, notes,
and tags. The CRM is the clearest payoff of unified identity — it is *the view of
the Contact* that every other module already feeds.

## Bounded context

**Identity** ([02-Domain](../02-Domain/)) — the CRM sits directly on core Contacts.

## Consumes

| Service / capability | For |
|---|---|
| Identity / Contacts | **is** the CRM's data — contacts, orgs, relationships |
| Events (platform-wide) | activity timeline from every module |
| Notifications | outreach, sequences |
| RBAC (resource-scoped) | reps see their assigned contacts |

## Provides

- Pipelines, deals, activities, tags on top of Contacts.
- Events `deal.won`, `deal.lost`, `activity.logged`.

## Owns

- `crm_pipelines`, `_deals`, `_activities`, `_notes` — all keyed by `contact_id`.
- **Does NOT own the person** — it *is* a rich view over core `contacts`; tags and
  relationships are core Contact features it surfaces, not copies.

## Why it's suddenly easy

On today's architecture a CRM is nearly impossible: a person is fragmented across
five tables, so "everything about this customer" can't be assembled. Once identity
is unified ([ADR-0006](../14-ADR/0006-unified-identity-contacts.md)):

- Every module's events (`order.paid`, `appointment.booked`,
  `membership.started`, `form.submitted`) carry a `contactId` → the CRM builds a
  **unified activity timeline** by subscribing, with **zero coupling** to those
  modules.
- A single Contact shows their orders, bookings, memberships, and form
  submissions — because they were always the same person.

This is the module that most vindicates the identity + events decisions; it's why
CRM is sequenced **after** v1's identity work, not before.

## Routes & admin

- Admin: contact 360 view, pipeline board, deal detail, activity timeline,
  segments/tags.

## Integration events

- **Subscribes:** essentially all domain events (to build timelines).
- **Emits:** `deal.won`/`deal.lost` → notifications, reporting.

---

## Related

- [../02-Domain/identity-contacts.md](../02-Domain/identity-contacts.md) · [../06-SDK/event-catalogue.md](../06-SDK/event-catalogue.md) · [../14-ADR/0006-unified-identity-contacts.md](../14-ADR/0006-unified-identity-contacts.md)
