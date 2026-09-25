# 08 — Notifications

**Status:** Draft · **Applies to:** Slate v3.x

## Purpose

Deliver messages to people across channels — email, SMS, WhatsApp, in-app, push —
driven by domain events, templated per tenant, and sent asynchronously. A
platform service other modules use instead of calling SMTP/Twilio directly.

## Bounded context

**Communication** ([02-Domain](../02-Domain/)). This is a **core service**
(`Slate\Services\Notifications`), **not a module** — it exposes the
`NotificationChannel` contract that any module may consume. It is documented in the
Modules section only because modules interact with it heavily
([README §Catalogue](README.md)).

## Consumes

| Service / capability | For |
|---|---|
| Events | the triggers (`order.paid`, `appointment.booked`, …) |
| Queue | asynchronous, retryable delivery ([13-Operations](../13-Operations/)) |
| Identity | recipient contact + per-contact preferences |
| Theme tokens | on-brand email templates ([05-Rendering/theme-and-template-engine.md](../05-Rendering/theme-and-template-engine.md)) |

## Provides

- The `NotificationChannel` capability + drivers:

```php
interface NotificationChannel {
    public function send(Recipient $to, Message $msg): DeliveryResult;
    public function supports(string $kind): bool;   // email|sms|whatsapp|in_app|push
}
```

- Templating with per-tenant/per-user preferences and a delivery log.

## Owns

- `notifications_templates`, `_preferences`, `_deliveries`, `_inapp` (slug-prefixed).
- **Does NOT own:** the events (emitted by other modules), the person (Contact +
  preferences keyed by `contact_id`).

## The shift from today

Today each plugin sends email **synchronously** on the request path via its own
Mailer calls. Here:

- Modules **emit events**; Notifications **subscribes** and sends — modules never
  touch a transport.
- Delivery is **queued** with retry/backoff, off the request path (fixes the
  synchronous-send bottleneck).
- **Multi-channel**: the same event can fan out to email + SMS + in-app per
  preferences. Mailer/Twilio become drivers.

## Integration events

- **Subscribes:** the events that warrant a message (order/appointment/membership/
  form/…).
- **Emits:** `notification.sent`/`notification.failed` (for dashboards/audit).

## Drivers & scale

Email via SMTP (default) or a provider API; SMS/WhatsApp via Twilio; in-app via the
notifications table + the topbar bell; push later. All behind the one channel
contract, selectable per tenant (ADR-0012 optionality).

---

## Related

- [../06-SDK/event-catalogue.md](../06-SDK/event-catalogue.md) · [../13-Operations/performance-and-caching.md](../13-Operations/performance-and-caching.md) · [../05-Rendering/theme-and-template-engine.md](../05-Rendering/theme-and-template-engine.md)
