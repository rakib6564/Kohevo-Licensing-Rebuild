# 06 — Developer SDK

**Status:** Draft · **Applies to:** Slate v1.x → v5.x · **Anchor document**

The SDK is the **stable, semver'd public surface that modules build against** —
and the *only* surface they are allowed to touch. If it is documented in this
section, a module may depend on it and trust it to keep working under the
backward-compatibility policy. If it is not documented here, it is **internal
plumbing that may change in any release**, and a module that reaches for it is
broken by design, not by us.

Read [00-Vision](../00-Vision/) principle 6 ("stable, semver'd SDK") and
[01-Architecture §5](../01-Architecture/#5-how-modules-communicate-the-decoupling-contract)
(the three communication channels) first — this section is how that promise is
kept concrete.

---

## 1. What the SDK *is* (and is not)

**WHAT.** A named, versioned set of five things:

1. **Base classes** you extend — `Module`, `Service`, `Repository`, `Entity`,
   `Block`, `Component`, `Controller`. → [base-classes-and-contracts.md](base-classes-and-contracts.md)
2. **Capability interfaces** you implement or consume — `PaymentGateway`,
   `IdentityStore`, `MediaLibrary`, `NotificationChannel`, `ShippingRateProvider`,
   `BlockProvider`, `SearchIndex`, `SeoMetaProvider`. → [base-classes-and-contracts.md](base-classes-and-contracts.md)
3. **The manifest v2 schema** — the declarative `module.json` the kernel reads to
   wire routes, nav, permissions, settings, events, and blocks *without booting
   your code*. → [manifest.md](manifest.md)
4. **The scaffolding CLI** (`bin/make module|block|migration`) and the
   step-by-step way to build a module correctly. → [building-a-module.md](building-a-module.md)
5. **The typed event & extension-point catalogue** — the facts you can react to
   and the value pipelines you can shape. → [event-catalogue.md](event-catalogue.md)

**WHAT IT IS NOT.** The SDK is *not* every public method in the codebase. The
Kernel container, the individual service implementations, the rendering pipeline
internals, the database driver, the migration runner — these are the platform's
*private organs*. Modules never name them. They are free to be rewritten,
re-optimized, or replaced between versions precisely because no module is coupled
to them.

> **The one-sentence contract.** *A module sees the platform only as: base classes
> it extends, capability interfaces it resolves, a manifest the kernel reads,
> events it emits/hears, and extension points it contributes to. Everything else is
> invisible and mutable.*

---

## 2. Why a stable surface exists (the problem it retires)

Today, modules couple to each other and to the core through **ambient global
state**, not contracts. The current patterns, catalogued in
[AUDIT-BRIEFING.md](../AUDIT-BRIEFING.md) and
[PLUGIN-API.md](../PLUGIN-API.md), are:

| Today (ambient coupling) | Why it hurts | SDK replacement |
|---|---|---|
| `if (class_exists('PassesAPI')) PassesAPI::foo()` | Consumer names a *concrete class* in another module. Rename it, retire it, or ship two providers and everything breaks. No versioning. | **Capability interface** resolved from the container. Consumer names a *contract*, never a class. |
| `Hook::addFilter(...)` / `addAction(...)` in `boot()`, all wiring imperative | Every module must `boot()` and run PHP just to *declare* a nav item or a route. The kernel can't know what a module offers without executing it. | **Declarative manifest v2** — routes/nav/permissions/events/blocks are data the kernel reads cold. |
| `ensureSchema()` / column-reconcile self-heal on every boot | Schema drift is "fixed" implicitly at runtime, per request, forever. No history, no rollback, silent divergence. | **Migrations** (versioned, ordered, reversible) run once. → [ADR-0010](../14-ADR/), [11-Database](../11-Database/). |
| Direct `SomePlugin::table` reads, `Database::setting('other.key')` | Reaches into another module's data. Uninstalling that module orphans callers. | **Capability interface** or **event** — you get the data through the owner's contract. |
| `float` prices, `DECIMAL` money, ad-hoc currency | Rounding bugs, mixed representations. | **`Money`** value object, everywhere. → [ADR-0011](../14-ADR/). |

The through-line: **the SDK turns conventions into contracts.** A convention is
"please don't touch my tables"; a contract is "you *cannot* name my tables, and
the tooling enforces it." Conventions rot; contracts version.

This is *reveal, don't rebuild* ([00-Vision §2](../00-Vision/)): the instincts —
hooks, capability brokering, schema-driven blocks — are right. The SDK formalizes
them into a surface that survives a decade of internal rewrites.

---

## 3. The semver guarantee

The SDK follows [Semantic Versioning](https://semver.org). The version that
matters to a module is the **SDK version**, exposed as `Slate\Sdk::VERSION` and
constrained in the manifest via `requires.sdk`.

| Change | Version bump | Examples |
|---|---|---|
| Backward-incompatible removal/rename of a documented surface | **MAJOR** | Delete a capability method; rename `Module::boot()`; change an event payload's existing field type; drop a manifest key. |
| Backward-compatible addition | **MINOR** | Add a new capability interface; add an *optional* method with a default; add a new event; add an optional manifest key; add a new base class. |
| Fix that changes nothing about the surface | **PATCH** | Docs, internal perf, bug fixes that keep the contract. |

**The promises MAJOR protects:**

- A module built against SDK `2.x` keeps working on every later `2.y` without
  code changes. New capability *methods* arrive with safe defaults; new events and
  extension points are additive; new manifest keys are optional.
- A documented surface is **never silently removed**. It is first **deprecated**
  (annotated, logged when used, dated), lives through at least one MINOR cycle with
  a documented migration, and only then removed in the next MAJOR. See
  [03-Standards](../03-Standards/) for the full deprecation & BC policy and
  [ADR-0009](../14-ADR/) for the decision.
- Internal (undocumented) surfaces carry **no** guarantee and may change in any
  release. The BC policy applies to *this section*, not to the whole codebase.

This is invariant #8 in [01-Architecture §7](../01-Architecture/#7-architectural-invariants-must-always-hold):
*the SDK surface changes only under the BC policy.* It is the precondition for a
third-party ecosystem — a developer can only invest a weekend building a module,
and a business can only ship one, if the surface will not shift under them.

---

## 4. The three channels (the whole decoupling model)

Every legal interaction between a module and the rest of the platform is one of
exactly **three channels** — no fourth channel is permitted
([01-Architecture §5](../01-Architecture/#5-how-modules-communicate-the-decoupling-contract)):

| Channel | Shape | When to use | Glossary |
|---|---|---|---|
| **Capability interface** | Synchronous request/response through a contract resolved from the container | You *need an answer now* — charge a card, resolve a contact, index a document | *Capability*, *Service* |
| **Event** | Past-tense fact broadcast to many listeners; fire-and-forget, order-independent, queue-safe | You *did something* and others may want to react — `order.paid`, `contact.created` | *Event* |
| **Extension point (filter)** | Present-tense value pipeline many contributors augment | You want to *shape shared data* — `nav.items`, `seo.meta`, `blocks.register` | *Extension point (filter)* |

The rule that makes 50+ modules tractable: **direct `ClassName::method()` across a
module boundary, or reading/writing another module's tables, is an architecture
violation** — caught in review and eventually by lint/CI. The three channels are
the *only* way out of your module. Everything in this section exists to make each
channel easy, typed, and pleasant to use.

The [building-a-module walkthrough](building-a-module.md) shows all three in one
module.

---

## 5. Index of this section

| Document | What it defines |
|---|---|
| [base-classes-and-contracts.md](base-classes-and-contracts.md) | The base classes you extend and the capability interfaces you implement/consume, with PHP-ish sketches. |
| [manifest.md](manifest.md) | The complete `module.json` v2 schema — identity, semver, `provides[]`/`requires[]`, and declarative routes/nav/permissions/settings/events/blocks — with a full annotated example. |
| [building-a-module.md](building-a-module.md) | Step-by-step: scaffold → manifest → migration → repository/service → routes/controllers → blocks → events → tests. The definition of done. |
| [event-catalogue.md](event-catalogue.md) | The typed core event catalogue (payloads) and the extension-point/filter catalogue. |

**Related sections:** [03-Standards](../03-Standards/) (coding standards, BC/deprecation
policy), [01-Architecture](../01-Architecture/) (kernel, service layer, event system,
plugin architecture), [07-API](../07-API/) (HTTP/REST, webhooks, payment API),
[11-Database](../11-Database/) (repository/migration framework, `Money`),
[08-Modules](../08-Modules/) (per-module specs), [14-ADR](../14-ADR/) (the decisions
behind all of it).

---

## 6. Migrating from Plugin API v1

The current contract ([PLUGIN-API.md](../PLUGIN-API.md),
[BUILDING-PLUGINS.md](../BUILDING-PLUGINS.md)) is SDK v1 in all but name. The
evolution is **additive and incremental** — *reveal, don't rebuild*:

| v1 term | SDK term | Continuity |
|---|---|---|
| Plugin | **Module** (glossary; ≡ Plugin) | Same package, formalized boundary. |
| `plugin.json` | `module.json` (manifest v2) | v2 is a superset; a v1 manifest is read via a shim during the transition. |
| `class extends Slate\Plugin` | `class extends Slate\Sdk\Module` | `Plugin` becomes a deprecated alias of `Module`. |
| `PassesAPI::foo()` + `class_exists` | `PaymentGateway` etc. capability interface | The API-class pattern is the *seed* of a capability; it graduates into a named contract. |
| `Hook::addFilter/addAction` in `boot()` | Declarative manifest wiring + typed event subscriptions | Imperative hooks still work; the manifest lets the kernel see wiring cold. |
| `install.sql` + `ensureSchema()` | Versioned migrations (`bin/make migration`) | Self-heal is retired in favor of ordered, reversible migrations. |

No module is asked to rewrite overnight. The compatibility shims (like the
`media-library` module already noted in [MEMORY](../../)) are the model: keep the
old surface working while the new one becomes canonical. The BC policy in
[03-Standards](../03-Standards/) governs the pace.
