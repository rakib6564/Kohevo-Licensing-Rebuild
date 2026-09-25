# 08 — Forms Module

**Status:** Draft · **Applies to:** Slate v3.x (unified)

## Purpose

Build public forms with many field types, conditional logic, multi-step flows,
e-signature, and PDF generation; collect and route submissions. A rebuild of
today's `forms` plugin that also **retires the legacy core contact-forms** (the
two coexisting systems collapse to one).

## Bounded context

**Communication** ([02-Domain](../02-Domain/)).

## Consumes

| Service / capability | For |
|---|---|
| Data / migrations | form definitions + submissions, tenant-scoped |
| Notifications | admin + submitter emails (channels, queued) |
| Events | broadcasting submissions |
| Blocks | a form embeddable as a Block |
| Identity (optional) | link a submission to a Contact when known |
| API / webhooks | signed, SSRF-guarded outbound delivery |

## Provides

- A `form` block; event `form.submitted`; ~20+ field types (incl. e-signature).

## Owns

- `forms_definitions`, `_submissions` (slug-prefixed).
- **Does NOT own:** notification transport (Notifications), webhook delivery (the
  platform [webhook framework](../07-API/webhooks.md)), or the person (links to a
  Contact when identifiable, never a copy).

## Routes & admin

- Public: `/forms/<slug>` + iframe embed; honeypot + per-IP rate limit.
- Admin: builder, submissions inbox, CSV export (formula-injection-safe), PDF
  download of signed agreements.

## Integration events

- **Emits:** `form.submitted` → CRM activity/lead creation, notifications,
  webhooks, automations. This is the key decoupling: a form doesn't *know* about
  the CRM; the CRM subscribes and creates a lead.
- **Subscribes:** `contact.merged`.

## Retiring the legacy system

The core contact-forms tables/UI are deprecated; a migration moves any legacy
submissions into the Forms module, after which the legacy tables are dropped (with
sign-off, given the [Phase-0](../09-Roadmap/refactor-roadmap.md) VCS safety net).
One forms system, not two.

## Security

Outbound webhooks reuse the platform SSRF guard; public submissions are
rate-limited; the PDF writer is dependency-free. All per
[10-Security](../10-Security/) and [07-API/webhooks.md](../07-API/webhooks.md).

---

## Related

- [notifications.md](notifications.md) · [crm.md](crm.md) · [../07-API/webhooks.md](../07-API/webhooks.md) · [../05-Rendering/blocks-and-sections.md](../05-Rendering/blocks-and-sections.md)
