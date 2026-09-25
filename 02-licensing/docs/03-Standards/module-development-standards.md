# 03 — Module Development Standards

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

The rules every **Module** must follow to be accepted into Slate. These turn the
[§7 invariants](../01-Architecture/README.md) and the
[three communication channels](../01-Architecture/README.md) into a concrete,
checkable contract. A module that satisfies this document is decoupled,
tenant-safe, and upgradeable; one that doesn't is a future migration.

> **Why these exist.** Today ([AUDIT-BRIEFING](../AUDIT-BRIEFING.md)) modules
> couple via `class_exists` on global classes, register everything imperatively
> in `boot()`, share each other's tables, store money as DECIMAL in one place and
> cents in another, and self-heal schema. Each rule below closes one of those
> gaps permanently.

---

## 1. Structure & namespacing

- **PSR-4 under `Slate\Module\<Slug>\…`.** No global classes.
- **Table prefix matches the slug** (`membership_*`, not `member_*`). Enables
  introspection and ownership enforcement.
- Standard layout ([plugin-architecture.md](../01-Architecture/plugin-architecture.md#1-what-a-module-is)):
  `module.json`, `src/`, `migrations/`, `views/`, `assets/`, `tests/`.

## 2. Ownership & isolation (invariant #1)

- A module creates/alters/drops **only tables it owns.** Never another module's
  tables; never core tables.
- A module references **no other module's classes.** Cross-module needs use a
  capability contract (sync) or an event (async).
- Fail-soft: a missing `optional` capability degrades; it never fatals.

## 3. Communication — the three channels only

| Need | Use | Not |
|---|---|---|
| Call another capability now | `kernel.get(PaymentGateway::class)` | `StripePaymentAPI::…` |
| React to a fact | subscribe to `payment.succeeded` | polling another module's tables |
| Shape shared data | extension point (`nav.items`, `blocks.register`) | editing core/other files |

## 4. Data & money (invariants #2, #3, #4)

- **Tenant-scope everything** via the base repository. Never hand-write
  `AND tenant_id = ?`. Cross-tenant reads use an audited `crossTenant()`.
- **All amounts are `Money`** (integer minor units + currency). No floats/DECIMAL
  for money.
- **A person is one `contacts` row.** Attach a module profile keyed by
  `contact_id`; never copy name/email/phone into a module-owned person table.

## 5. Schema evolution

- **Versioned migrations** in `migrations/`, ordered and reversible
  ([11-Database/migrations.md](../11-Database/migrations.md)). No
  `CREATE TABLE IF NOT EXISTS` self-heal on the request path.
- `up`/`down` touch only owned tables. `down` must be a true inverse.

## 6. Declarative wiring

Everything static — routes, nav, permissions, settings schema, event
subscriptions, blocks — is declared in `module.json`
([06-SDK/manifest.md](../06-SDK/manifest.md)), not registered imperatively.
`boot()` does *live wiring only* (subscriptions, provider registration).

## 7. Security & rendering

- Permissions declared in the manifest as `<domain>.<action>`; authorize through
  the policy engine ([10-Security](../10-Security/authorization-rbac.md)).
- All output escaped; all input validated; uploads MIME-checked; outbound HTTP
  SSRF-guarded ([10-Security](../10-Security/)).
- Public UI renders through the **one pipeline** and composes **Components**
  (invariant #6) — no bespoke `<html>` documents.

## 8. Tests (definition of done)

- Unit + integration tests covering the module's **money, auth, and tenancy**
  paths at minimum ([12-Testing](../12-Testing/)).
- Passes the architecture-conformance checks
  ([12-Testing/architecture-conformance.md](../12-Testing/architecture-conformance.md)).

---

## Definition of Done (the gate)

A module merges only when **all** hold:

- [ ] PSR-4; slug-matched table prefix.
- [ ] Owns only its tables; references no other module's classes/tables.
- [ ] Talks only via contracts, events, extension points.
- [ ] `Money` everywhere; tenant-scoped everywhere; one `contacts` row per person.
- [ ] Versioned reversible migrations; no `ensureSchema`.
- [ ] Declarative manifest wiring; `boot()` is live-wiring only.
- [ ] Permissions declared + authorized via the policy engine; I/O hardened.
- [ ] Tests for money/auth/tenancy; conformance green.
- [ ] Any structural decision recorded as an [ADR](../14-ADR/).

---

## Related

- [README.md](README.md) (coding standards) · [versioning-and-compatibility.md](versioning-and-compatibility.md)
- [06-SDK/building-a-module.md](../06-SDK/building-a-module.md) · [15-Contributing](../15-Contributing/)
