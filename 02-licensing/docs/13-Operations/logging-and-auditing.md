# 13 — Logging & Auditing

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

Two distinct trails with different jobs: **application logs** (diagnostics for
developers/ops) and the **audit log** (business facts for compliance). Conflating
them is a common mistake; Slate keeps them separate and feeds security events to
both.

---

## 1. Application logs (diagnostics)

- **Structured** entries (level, message, context, `tenant_id`, **correlation
  id** from the front controller — [10-Security/error-handling.md](../10-Security/error-handling.md)).
- **Levels:** debug/info/notice/warning/error/critical. Prod logs info+; dev logs
  debug.
- **Log drivers** behind an interface: files by default (shared hosting), an
  aggregator (syslog/HTTP) optional at scale (ADR-0012). Never the
  browser — `display_errors` is off.
- **Never contains secrets or PII beyond what's needed to diagnose**; tokens and
  card data are never logged.

Purpose: answer "what went wrong with request `req_01H…`?" quickly.

## 2. Audit log (business facts)

- Records **who did what to what**: `(tenant_id, actor, action, target,
  meta, ip, created_at)` — e.g. `user 5 refunded order 42`, `admin changed role
  permissions`, `data.cross_tenant accessed`.
- **Append-only**, retained per policy, queryable in the admin UI.
- Written explicitly by services at the point of a meaningful action — **not**
  inferred from row diffs, so it captures intent, not just state change.

Purpose: answer "who did this, when?" for compliance, support, and forensics.

## 3. The split, at a glance

| | Application log | Audit log |
|---|---|---|
| Audience | developers / ops | admins / compliance |
| Content | diagnostics, errors, timings | business actions |
| Shape | leveled, correlation-id'd | actor/action/target |
| Retention | short-medium, rotated | long, policy-driven |
| Surfaced in | log tooling | admin UI |

## 4. Security events feed both

An authorization denial, an SSRF refusal, a throttle lockout, a payment mismatch:
logged (diagnostics + correlation id) **and** audited (the security-relevant fact)
— so ops can debug and compliance can review the same event from each angle
([10-Security/error-handling.md](../10-Security/error-handling.md)).

## 5. Observability (scale)

At the enterprise posture, logs ship to an aggregator, correlation ids tie across
services, and dashboards track error rates, queue depth, cache hit-rate, and
request latency ([performance-and-caching.md](performance-and-caching.md)). The
correlation id designed in from day one is what makes this tractable later.

## 6. Cleanliness

Dev/debug scripts and stray error output are **not** an operational logging
strategy (today's `error_log` noise). Diagnostics go through the log interface;
debug artifacts never ship to production ([15-Contributing](../15-Contributing/)).

---

## Related

- [README.md](README.md) · [performance-and-caching.md](performance-and-caching.md)
- [10-Security/error-handling.md](../10-Security/error-handling.md) · [01-Architecture](../01-Architecture/) · [14-ADR](../14-ADR/)
