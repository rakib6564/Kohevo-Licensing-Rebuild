# 14 — Architecture Decision Records

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

An **ADR** captures one significant architectural decision: its context, the
decision, the alternatives weighed, and the consequences accepted. Over 10–15
years these records answer the question a future developer always asks — *why is
it built this way?* — so decisions aren't silently re-litigated or accidentally
reversed.

---

## The rules (from the [hub policy](../README.md))

- **Append-only.** An ADR is never edited away. When a decision changes, write a
  new ADR and mark the old one `Superseded by ADR-NNNN`. The reasoning trail is
  permanent.
- **One decision per record.** Numbered sequentially, never renumbered.
- **A new structural decision needs an ADR** ([15-Contributing](../15-Contributing/)):
  a new cross-cutting pattern, a boundary move, a technology choice, a reversal.
- **Statuses:** `Proposed` · `Accepted` · `Superseded`.

## Template

```
# ADR-NNNN — Title
**Status:** Proposed | Accepted | Superseded by ADR-MMMM   **Date:** YYYY-MM-DD
## Context      — the forces and the real problem (cite the audit where relevant)
## Decision     — what we chose, precisely
## Alternatives — what else was considered, and why rejected
## Consequences  — positive AND negative; the trade-offs we accept
## Related       — ADRs and hub sections
```

## Index — founding set (all Accepted, 2026-07-27)

| ADR | Decision |
|---|---|
| [0001](0001-flat-php-over-framework.md) | Flat PHP over a full framework |
| [0002](0002-modular-monolith-over-microservices.md) | Modular monolith over microservices |
| [0003](0003-server-rendered-no-build.md) | Server-rendered + progressive enhancement; no build step |
| [0004](0004-kernel-container-contracts.md) | Kernel + service container + capability contracts |
| [0005](0005-events-and-contracts-for-modules.md) | Events + contracts for inter-module communication |
| [0006](0006-unified-identity-contacts.md) | Unified Identity/Contacts model |
| [0007](0007-section-block-before-page-builder.md) | Section/Block content model before a visual Page Builder |
| [0008](0008-one-design-token-vocabulary.md) | One design-token vocabulary for admin + public |
| [0009](0009-semver-sdk-bc-policy.md) | Semver'd SDK with a backward-compatibility policy |
| [0010](0010-migrations-over-ensureschema.md) | Migration framework over `ensureSchema()` self-heal |
| [0011](0011-money-value-object.md) | `Money` value object (integer minor units) platform-wide |
| [0012](0012-swappable-driver-interfaces.md) | Swappable driver interfaces for shared-hosting↔enterprise |
| [0013](0013-namespace-strategy-and-src-layout.md) | Namespace strategy & `src/` layout (`Slate\…` → `src/`, 10 layer namespaces) |
| [0014](0014-structured-builder-contracts-and-derived-html.md) | Structured builder contracts and derived `content_html` |

ADRs 0001–0012 correspond to [01-Architecture §8](../01-Architecture/README.md);
0013+ are added as new structural decisions are ratified (per the amend-first
policy).
