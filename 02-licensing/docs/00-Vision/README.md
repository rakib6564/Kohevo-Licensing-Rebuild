# 00 — Vision & Philosophy

**Status:** Draft · **Applies to:** Slate v1.x → v5.x · **Anchor document**

This section defines *what Slate becomes* and the principles that govern every
decision after it. It is deliberately short and stable — it should barely change
for years. Everything else in the hub serves this.

---

## 1. Platform vision

**Slate is a complete, modular application platform for building any web
product — business sites, booking systems, CRM, membership, eCommerce, LMS, and
SaaS — without depending on a CMS or a third-party framework, and without a build
step.**

The promise has three parts:

1. **Build anything.** A shared foundation (identity, data, payments, rendering,
   events) that any vertical composes on top of — so a booking site, a store, and
   a membership CRM are the *same platform* wearing different modules, not
   separate codebases.
2. **Deploy anywhere.** Upload-and-run on commodity shared hosting (PHP + MySQL +
   Apache), scaling up to VPS/containers for enterprise — the *same code*, the
   storage/cache/queue strategy swapped behind interfaces.
3. **Evolve for a decade.** A stable, versioned developer surface so the platform
   can be rebuilt internally many times without breaking the modules — and
   eventually the third-party ecosystem — built on it.

Slate succeeds when a developer can stand up a professional, themed,
SEO-complete, multi-tenant business application in a weekend, extend it with a
module in an afternoon, and trust that neither will need a rewrite in five years.

---

## 2. Core philosophy

- **Reveal, don't rebuild.** Slate's instincts — hook-based extensibility,
  tenancy everywhere, a centralized payment gateway, schema-driven blocks — are
  right. The job is to *formalize* them into contracts, not to replace them with a
  framework. There is no big-bang rewrite in this vision.
- **A platform is its boundaries.** What makes something a platform rather than an
  app is that the seams between parts are explicit and enforced. We invest in
  boundaries (contracts, events, ownership rules) first, features second.
- **One concept, one owner.** One identity model, one money type, one token
  vocabulary, one block registry, one settings accessor. Every duplicated concept
  is a future migration and a class of bugs. Killing duplication is a first-order
  goal, not cleanup.
- **Boring where it counts.** Payments, identity, tenancy, and data access are
  designed to be predictable and hard to misuse. Creativity belongs in modules
  and themes, not in how money is stored or how a tenant is scoped.
- **Developer joy is a feature.** The platform must be *enjoyable* to build for —
  scaffolding, clear contracts, good errors, fast local iteration. An ecosystem
  only grows on a surface developers like.

---

## 3. Design principles

1. **Modular monolith, not microservices.** One deployable, cleanly separated
   inside by contracts. Seams (events, service interfaces, an HTTP API) let pieces
   be extracted *if* scale ever demands — but we never pay distributed-systems tax
   we don't need. (See ADR-0002.)
2. **Kernel + Contracts + Modules.** The kernel wires services; services provide
   capabilities; modules are guests depending on interfaces and events, never on
   each other's classes or tables. (See ADR-0004, ADR-0005.)
3. **Composition over inheritance; data over code for wiring.** Static wiring
   (routes, nav, permissions, settings schema) is declared as data the kernel
   reads without booting every module.
4. **Server-rendered, progressively enhanced.** HTML renders and is crawlable
   without JavaScript; JS enhances. This keeps SEO free and ops simple. (See
   ADR-0003.)
5. **No build step on the server.** Assets are runtime-composed and cached, never
   webpack-compiled at deploy. Contributors can edit a file and refresh. (See
   ADR-0001, ADR-0003.)
6. **Stable, semver'd SDK.** The surface modules build against changes only under
   a deprecation policy — the precondition for a third-party ecosystem. (See
   ADR-0009 and [03-Standards](../03-Standards/).)
7. **Secure and multi-tenant by default.** Tenant scoping and authorization are
   structural (you opt *out*, loudly and audibly), not something each author
   remembers to add. (See [10-Security](../10-Security/).)
8. **Process protects architecture.** Version control, tests, and CI are part of
   the architecture, not adjacent to it — a perfect design with no safety net
   erodes back into coupling within a year. (See [12-Testing](../12-Testing/).)

---

## 4. Real-world constraints (non-negotiable)

The design is bounded by where Slate actually runs. These constraints are not
limitations to escape; they are load-bearing product decisions.

| Constraint | Consequence for the architecture |
|---|---|
| Flat PHP 8.1+, deploy by upload | Front controller + PSR-4 autoload; no framework kernel; no server build |
| Shared CloudLinux/cPanel, subpath (`/slate/`) | Base-path–aware routing/assets; file/APCu default drivers for cache & queue |
| One MySQL/MariaDB database | Shared-DB multi-tenancy default; heavier tenancy is an optional driver, not a rewrite |
| No mandatory Node/webpack | Runtime asset composition; server-rendered components; vanilla CSS tokens |
| Multi-tenant from row one | `tenant_id` on every table; tenant context injected into every service |
| Backward compatibility matters | Incremental, contract-preserving evolution; a formal BC policy (03-Standards) |

**The optionality principle:** every scale-sensitive concern (cache, queue,
tenancy storage, search) is defined as an **interface with a shared-hosting
default driver and an optional heavier driver**. The platform runs on $4/month
hosting today and on a Redis-backed VPS cluster for an enterprise tenant
tomorrow — *without touching a single module*. This is how "deploy anywhere" and
"scale to enterprise" coexist.

---

## 5. What Slate is *not*

Stating the non-goals prevents scope drift:

- **Not a framework to compete with Laravel/Symfony.** It embeds only what a
  platform needs; it does not chase general-purpose framework parity. (ADR-0001.)
- **Not an SPA/JS platform.** No hydration tax, no build-first workflow. JS is an
  enhancement layer. (ADR-0003.)
- **Not a microservice mesh.** It is a modular monolith with clean seams.
  (ADR-0002.)
- **Not WordPress.** It borrows WP's extensibility ethos but rejects global
  coupling, the options-table sprawl, and the fragmented data model that make WP
  hard to reason about at scale.

---

## 6. How the vision cascades

Every downstream document must trace back to a principle here:

- **[01-Architecture](../01-Architecture/)** realizes *Kernel + Contracts +
  Modules* and *modular monolith*.
- **[04-Design-System](../04-Design-System/)** + **[05-Rendering](../05-Rendering/)**
  realize *one token vocabulary* and *server-rendered, progressively enhanced*.
- **[06-SDK](../06-SDK/)** + **[03-Standards](../03-Standards/)** realize *stable
  semver'd SDK* and *developer joy*.
- **[10-Security](../10-Security/)** + **[11-Database](../11-Database/)** realize
  *secure and multi-tenant by default* and *boring where it counts*.
- **[13-Operations](../13-Operations/)** realizes *no build step* and the
  *optionality principle*.
- **[09-Roadmap](../09-Roadmap/)** realizes *reveal, don't rebuild* — the
  incremental path with no big-bang rewrite.

If a proposed feature can't be traced to a principle here, that's the signal to
stop and reconsider — either the feature or, deliberately, the vision.
