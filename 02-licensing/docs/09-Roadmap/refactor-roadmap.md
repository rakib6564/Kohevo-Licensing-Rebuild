# 09 — Refactor Roadmap (Today → Blueprint)

**Status:** Draft · **Applies to:** current codebase → Slate v1.x

The incremental, backward-compatible path from *today's* code to the
[architecture blueprint](../01-Architecture/). It realizes the vision principle
**reveal, don't rebuild** ([00-Vision](../00-Vision/)): no big-bang rewrite, one
seam formalized at a time, working software at every step. The full current-state
analysis lives in [ARCHITECTURE-ROADMAP.md](../ARCHITECTURE-ROADMAP.md) and
[AUDIT-BRIEFING.md](../AUDIT-BRIEFING.md); this document is its hub-resident,
phase-sequenced form.

**Sequencing principle:** fix the things that get *exponentially harder the more
modules exist* first (identity, contracts, namespaces, migrations); defer the
things that stay equally easy later (perf caching, a settings UI).

---

## Phase 0 — Safety net *(days; prerequisite for everything)*

Nothing structural is safe until there is something to catch regressions.

- `git init` + first commit of the working tree. **There is no version control
  today** — the single highest-leverage, lowest-cost fix.
- A minimal smoke/test harness ([12-Testing](../12-Testing/)) covering money,
  auth, tenancy, and payments happy-paths.
- A CI gate that runs it on push.

**Exit:** any later change is reversible and regression-checked.

---

## Phase 1 — Kernel substrate *(invisible, foundational)*

- **PSR-4 autoload + namespaces** — dissolves the global-class / `class_exists`
  coupling at the root ([kernel.md](../01-Architecture/kernel.md)).
- **Front controller** — replaces per-file `require config.php` entry points.
- **Migration framework** ([11-Database](../11-Database/migrations.md)) —
  replaces the `ensureSchema()` self-heal.
- **Data layer with automatic tenant scoping** ([multi-tenancy.md](../01-Architecture/multi-tenancy.md))
  — removes the manual `AND tenant_id = ?` leak surface.

**Exit:** the foundation stops fighting new work. No user-facing change.

---

## Phase 2 — Identity unification *(highest value, hardest if deferred)*

- Introduce the core **Contacts/Identity** service ([02-Domain](../02-Domain/identity-contacts.md)).
- Migrate `booking_customers`, `shop_customers`, `restaurant_customers`,
  `clientdesk_clients` to link to (and merge on) one `contacts` row via a
  dual-write window + BC shim.

**Exit:** a person is one row. A real CRM becomes possible (Phase 3 / v3).

---

## Phase 3 — Module contracts *(decoupling)*

- **Service container + capability interfaces**; `provides`/`requires` manifest
  and the dependency resolver ([plugin-architecture.md](../01-Architecture/plugin-architecture.md)).
- **`PaymentGateway` first** — decouples the bidirectional shop↔stripe edge
  ([07-API/payments.md](../07-API/payments.md)).
- Enforce **table-ownership isolation** (makes prefix collisions impossible, not
  just conventionally avoided).
- Standardize money on the **`Money` value object** (converts `shop`'s DECIMAL).

**Exit:** modules talk only through contracts + events; 50 modules become
tractable.

---

## Phase 4 — Render / theme unification *(then the Page Builder)*

- One **design-token vocabulary** ([04](../04-Design-System/)); merge the three
  admin component kits.
- Promote `BlockRegistry` to core; evict `rx-*` verticals; resolve the parallel
  `sb-*` system; make **Section/Template first-class** ([05](../05-Rendering/)).
- **Then** build the visual Page Builder on the stabilized stack.

**Exit:** one render pipeline serves admin, public, storefront, email.

---

## Phase 5 — Performance & scale

- **Boot/manifest cache** — kills per-request `plugin.json` reads + settings
  lookups.
- Settings cache; move `ensureSchema` remnants off the hot path; full-page +
  fragment caching ([13-Operations](../13-Operations/performance-and-caching.md)).

**Exit:** steady-state cost is flat as module count grows.

---

## What NOT to do

- **Don't rewrite from scratch.** The hook system, tenancy columns, centralized
  Stripe, and `BlockRegistry` are keepers — this is refactor-in-place.
- **Don't build the Page Builder before Phase 4.** It's the top of the stack.
- **Don't add CRM / LMS / SaaS before Phases 2–3.** They are the biggest
  consumers of the identity + contract layers that must exist first.
- **Don't do any destructive migration before Phase 0.** No VCS = no undo.

---

## Mapping to versions

Phases 0–3 land the **v1.x** foundation; Phase 4 is **v2.x**; the verticals
rebuilt on the spine are **v3.x**. See [implementation-roadmap.md](implementation-roadmap.md).
