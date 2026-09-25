# Slate Documentation Hub

This is the **technical constitution** of the Slate platform — a living
documentation set that evolves alongside the codebase. It is the single source of
truth that every refactor, plugin, and feature is measured against.

> **Rule of the hub:** if a change doesn't fit what's written here, we amend the
> relevant document *first* — deliberately and reviewed — rather than making
> architecture by accident. The docs lead the code.

> **Design intent:** these documents describe the **ideal long-term platform**,
> adapted to Slate's real constraints (flat PHP, shared hosting, no build step,
> multi-tenancy, backward compatibility). They describe the destination, not the
> current implementation. Where today's code diverges, that divergence is tracked
> in [09-Roadmap](09-Roadmap/) — not silently baked into the design.

---

## Map of the hub

| Dir | Section | What lives here |
|---|---|---|
| [00-Vision](00-Vision/) | **Vision** | What Slate becomes; core philosophy; design principles; constraints |
| [01-Architecture](01-Architecture/) | **Architecture** | Master blueprint, kernel, request lifecycle, service layer, event system, plugin architecture, multi-tenancy |
| [02-Domain](02-Domain/) | **Domain** | Domain model, bounded contexts, Identity & Contacts architecture |
| [03-Standards](03-Standards/) | **Standards** | Coding standards, module-development standards, versioning & backward-compatibility policy |
| [04-Design-System](04-Design-System/) | **Design System** | Design tokens, component library, theming, accessibility |
| [05-Rendering](05-Rendering/) | **Rendering** | Tokens→Components→Blocks→Sections→Pages, rendering pipeline, Theme Engine, future Visual Page Builder, assets, SEO rendering |
| [06-SDK](06-SDK/) | **SDK** | Developer SDK: base classes, contracts, scaffolding, event catalogue, plugin standards |
| [07-API](07-API/) | **API** | HTTP/REST API, authentication, webhooks, payment API, versioning |
| [08-Modules](08-Modules/) | **Modules** | Per-module specifications (Website/CMS, Booking, Shop, Membership, CRM, LMS, Forms, Notifications, Search…) |
| [09-Roadmap](09-Roadmap/) | **Roadmap** | Refactor roadmap (from today) + implementation roadmap (v1→v5) |
| [10-Security](10-Security/) | **Security** | Security architecture, authentication, RBAC/policy, error handling |
| [11-Database](11-Database/) | **Database** | Data layer, repository/service pattern, migration framework, schema conventions |
| [12-Testing](12-Testing/) | **Testing** | Testing strategy, layers, tooling, CI |
| [13-Operations](13-Operations/) | **Operations** | Deployment, shared-hosting compatibility, performance, caching, logging & auditing |
| [14-ADR](14-ADR/) | **Decision Records** | One append-only record per major architectural decision |
| [15-Contributing](15-Contributing/) | **Contributing** | How to contribute code and docs; how to keep this hub alive |

**Read order — new developer:** 00 → 01 → 06 → 05 → 08.
**Read order — decision-maker:** 00 → 01 → 09 → 14.
**Read order — reviewer of a change:** the touched section → 03 → 14.

---

## Canonical glossary (fixed vocabulary)

These terms mean exactly this across every document. Do not redefine them locally.

- **Kernel** — domain-agnostic plumbing: service container, module manager, event
  bus, request lifecycle. Wires the system; contains no business logic.
- **Service** — a container-resolved capability with a stable interface (Identity,
  Payments, Media, SEO, Cache…). Owns its data; reachable only via its interface
  or its events.
- **Module** (≡ Plugin; we prefer **Module**) — a packaged vertical/feature. A
  guest that depends on service **contracts** and **events**, never on another
  module's classes or tables.
- **Capability** — a named contract a module *provides* or *requires* (e.g.
  `PaymentGateway`). The kernel brokers capabilities to keep modules decoupled.
- **Event** — a past-tense fact on the bus (`order.paid`). Many listeners,
  side-effects only, order-independent, queue-safe. The primary inter-module
  channel.
- **Extension point (filter)** — a present-tense value pipeline (`seo.meta`,
  `nav.items`) contributors augment. For shaping shared data, not RPC.
- **Design Token** — a themeable CSS custom property (`--slate-*`); the single
  styling vocabulary for admin *and* public.
- **Component** — a presentational, server-rendered UI primitive consuming only
  tokens. No domain knowledge, no editable content.
- **Block** — an editable content unit: field schema + a render that *composes
  Components*. Registered in the core Block Registry.
- **Section** — a layout container arranging Blocks (columns, background,
  spacing). First-class, saveable, reusable.
- **Layout** — the grid/flow rules a Section or Template applies.
- **Template** — the document skeleton: head assembly, chrome, named regions.
- **Page** — a Template + an ordered list of Sections, for a content type.
- **Theme** — token values + font pairings + chrome presets + component variants
  that skin the whole platform.
- **Tenant** — an isolated customer of an install; `tenant_id` scopes all data;
  the storage strategy behind it is swappable.
- **Contact / Identity** — the single model of a person/organization. One
  identity, many module **profiles** keyed by `contact_id`.
- **SDK** — the stable, semver'd public surface modules build against.
- **Money** — the platform value object: integer minor units + currency. The only
  legal way to represent money.

---

## Layer-boundary table (what belongs where)

The most-referenced rule set. When unsure where code goes, this table decides.
The full narrative is in [01-Architecture](01-Architecture/) and
[05-Rendering](05-Rendering/).

| Artifact | Owns / contains | Must NOT contain | Depends on |
|---|---|---|---|
| **Core (Kernel)** | container, module manager, event bus, lifecycle | any domain/business logic | nothing |
| **Service** | one cross-cutting capability + its data + interface | UI, another service's data, module specifics | kernel, Data Layer |
| **Module** | one vertical's logic, its own tables, its blocks/routes/UI | other modules' classes/tables; core-table writes | SDK (services + events) |
| **Theme** | token *values*, font pairings, chrome presets, component variants | markup structure, business logic, content | tokens, component contracts |
| **Component** | presentational markup + variants consuming tokens | editable content, data access, domain logic | Design Tokens only |
| **Block** | editable field schema + render composing Components | layout of *other* blocks; direct DB; chrome | Components, Media/Asset contracts |
| **Section** | arrangement/layout of Blocks; background, columns, spacing | a Block's internal rendering; page chrome | Blocks |
| **Layout** | grid/flow rules used by a Section or Template | content; token *values* | Design Tokens |
| **Template** | document skeleton, regions, head/chrome assembly | Section/Block internals; business logic | Sections, Theme, SEO |
| **Page** | Template choice + ordered Sections + content-type binding | rendering mechanics | Template, Sections |

**One-liners:** Core = plumbing no module should reimplement. Module = one bounded
domain that owns its tables and talks via contracts. Theme = *how it looks*
(values), never *what it is* (structure). Component = a widget with no memory of
content. Block = editable content; Section = how blocks are arranged; Layout =
the grid rules; Template = the page frame; Page = the assembled result.

---

## How this hub stays alive (anti-staleness policy)

The core requirement: this must not rot after a few months.

1. **Amend-first.** No large architectural change merges without its doc change in
   the same commit. Doc changes are reviewed like code.
2. **ADRs are append-only.** A superseded decision is marked
   `Superseded by ADR-NNNN`; the reasoning trail is never deleted. See
   [14-ADR](14-ADR/).
3. **This hub stays high-level and stable.** Volatile detail (exact schemas,
   signatures) graduates into per-layer design docs referenced from here.
4. **Versioned with the platform.** Each document header carries the version range
   it describes; doc↔code divergence is a tracked bug, not a fact of life.
5. **Every document states its status:** `Draft` · `Accepted` · `Superseded`.

---

## Document status — FROZEN

> **Slate Platform Architecture v1.0 — Status: Accepted / Frozen (2026-07-27).**
> Validated end-to-end (see [ARCHITECTURE-VALIDATION-REPORT.md](ARCHITECTURE-VALIDATION-REPORT.md),
> verdict PASS) and tagged `architecture-v1.0`. This hub (root `README.md` +
> sections `00`–`15`, all 13 ADRs, and the Core Platform Foundation Standard) is
> now the **single source of truth** for implementation.
>
> **From this point forward:**
> - Architectural changes require an **[ADR](14-ADR/)** (amend-first — the doc
>   changes in the same commit as the code).
> - No undocumented structural decisions.
> - All code follows the [Foundation Standard](03-Standards/platform-foundation.md)
>   and passes the reviewer checklist / conformance checks.

**Companion, living (not frozen):** the [Current Implementation Reference](current-implementation/)
tracks the code *as built* and evolves with it; [AUDIT-BRIEFING.md](AUDIT-BRIEFING.md)
and [ARCHITECTURE-ROADMAP.md](ARCHITECTURE-ROADMAP.md) are historical. The
incremental path from as-built to the frozen target is [09-Roadmap](09-Roadmap/).
