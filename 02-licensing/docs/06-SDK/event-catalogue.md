# 06 — Event & Extension-Point Catalogue

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

The typed, versioned catalogue of **domain events** (past-tense facts) and
**extension points** (present-tense value pipelines) that make up the async and
augmentation channels ([01-Architecture §5](../01-Architecture/README.md),
[event-system.md](../01-Architecture/event-system.md)). This catalogue is part of
the covered SDK surface — event names and payload shapes change only under the
[BC policy](../03-Standards/versioning-and-compatibility.md).

> **Why a catalogue.** Today ([AUDIT-BRIEFING](../AUDIT-BRIEFING.md)) hooks are
> bare global strings with undocumented payloads discovered by grep. A typed
> catalogue makes the integration surface discoverable and stable: each event is
> a class with a defined payload, versioned like any other contract.

---

## 1. Domain events (facts — subscribe to react)

Emitted after a fact is durably true. Many listeners, order-independent,
side-effects only, queue-safe. Each is a typed payload class.

| Event | Payload (key fields) | Emitted by |
|---|---|---|
| `contact.created` | `contactId, tenantId, source` | Identity |
| `contact.merged` | `survivorId, mergedId` | Identity |
| `identity.registered` | `contactId, method` | Auth |
| `payment.succeeded` | `amount:Money, ref, gateway, chargeId` | Payments |
| `payment.refunded` | `amount:Money, ref, chargeId` | Payments |
| `order.placed` | `orderId, contactId, total:Money` | Shop |
| `order.paid` | `orderId, contactId, total:Money` | Shop |
| `appointment.booked` | `apptId, contactId, serviceId, start` | Booking |
| `appointment.canceled` | `apptId, reason` | Booking |
| `membership.started` | `membershipId, contactId, plan` | Membership |
| `membership.expired` | `membershipId, contactId` | Membership |
| `form.submitted` | `formId, contactId?, dataRef` | Forms |
| `page.published` | `pageId, url` | Website/CMS |

**Contract:** a payload gains fields (MINOR) but never removes/repurposes one
(MAJOR). `Money` fields are always `Money` — never scalars (invariant #3).

```php
Events::on('payment.succeeded', fn (PaymentSucceeded $e) => /* $e->amount is Money */ ...);
```

---

## 2. Extension points (filters — contribute to shape data)

Present-tense value pipelines: contributors receive a value and return a
(possibly augmented) value, in priority order. For shaping shared data — never
for RPC.

| Extension point | Value threaded | Used to |
|---|---|---|
| `nav.items` | admin nav array | add nav entries (respecting perms) |
| `customer_nav.items` | portal nav array | add portal entries |
| `blocks.register` | the Block Registry | register block types |
| `seo.meta` | resolved meta bag | override/add title/description/OG/canonical |
| `sitemap.collect` | URL set | contribute a module's public URLs |
| `shipping.rates` | rate list + cart context | offer shipping rates |
| `dashboard.widgets` | widget list | add admin dashboard widgets |
| `head.tags` | `<head>` fragment list | inject scripts/styles/meta |
| `contact.merge` | merge plan | let a module move its profile on identity merge |

```php
Hook::filter('seo.meta', function (MetaBag $m, Renderable $entity) {
    if ($entity instanceof Product) $m->image ??= $entity->primaryImageUrl();
    return $m;
}, priority: 10);
```

---

## 3. Rules

- **Facts vs augmentation.** If it *happened*, it's an event (`order.paid`). If it
  *shapes a value in flight*, it's an extension point (`seo.meta`). Never use an
  event for request/response between modules — that's a capability contract.
- **Namespaced, dotted names.** `<domain>.<verb>` for events; `<area>.<noun>` for
  extension points. No bare globals.
- **Error isolation.** A throwing listener/contributor is logged and skipped;
  dispatch continues ([event-system.md](../01-Architecture/event-system.md)).
- **Queue-safety.** Listeners must tolerate async execution (v3+ moves slow
  listeners — notifications, webhooks — onto the queue).

---

## 4. Registering a new event

A module MAY define its own events (`donations.received`) — declare them in its
SDK docs with a typed payload, follow the naming + BC rules, and they become part
of *that module's* covered surface. Core events live here; module events live in
the module's own reference.

---

## Related

- [01-Architecture/event-system.md](../01-Architecture/event-system.md) · [base-classes-and-contracts.md](base-classes-and-contracts.md)
- [05-Rendering/seo-rendering.md](../05-Rendering/seo-rendering.md) · [08-Modules](../08-Modules/) · [03-Standards](../03-Standards/versioning-and-compatibility.md)
