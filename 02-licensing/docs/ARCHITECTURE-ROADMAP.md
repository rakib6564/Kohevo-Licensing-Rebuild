# Slate — Architecture Assessment & Long-Term Roadmap

> **Superseded / absorbed.** This early assessment has been folded into the
> Documentation Hub and is kept only as a historical artifact. The normative
> roadmap now lives in [09-Roadmap](09-Roadmap/) (refactor + implementation), and
> the current-state audit in [AUDIT-BRIEFING.md](AUDIT-BRIEFING.md). It is **not**
> part of the frozen Slate Platform Architecture v1.0 spec.

**Prepared:** 2026-07-27
**Question on the table:** Can today's core carry 10–15 years of modules
(business sites, booking, CRM, membership, eCommerce, LMS, SaaS), and what
must change *before* we build more?
**Method:** Evidence-based read of core runtime, cross-plugin coupling, and the
UI/theme/content stack (file:line references throughout).

---

## 0. Honest verdict

Slate is a **well-built v1 application shell** — but it is **not yet a platform
foundation**. The bones are genuinely good: a WordPress-style hook system with
error isolation, `tenant_id` on every table, a genuinely centralized Stripe
gateway, a solid security baseline, and — importantly — the `content-builder`
`BlockRegistry`/`Renderer` is a real, schema-driven rendering spine you can
build a Page Builder on.

But the platform today works the way early WordPress did: **plugins reach
directly into each other's concrete classes, invent their own copies of shared
concepts (customers, themes, blocks, settings), and each render their own HTML
document.** That is survivable at 19 plugins. It becomes unmaintainable at 50,
and it will actively fight the CRM/LMS/SaaS vision because those verticals all
revolve around the *shared* concepts that are currently fragmented.

**The good news:** none of this needs a rewrite. It needs a **kernel** — a set
of first-class services (identity, money, data/tenancy, events, rendering) that
modules depend on through *contracts* instead of reaching into each other. The
work is to graduate from **"shell + plugins"** to **"kernel + contracts +
modules."** Do that spine-work *before* the next vertical, or every vertical
adds to the debt.

**Sequencing principle:** fix the things that get *exponentially harder* the
more modules exist (identity model, module contracts, migrations, namespaces)
first. Fix the things that stay equally easy later (perf caching, a settings
UI) last.

---

## 1. Is the core scalable enough for the vision?

**Scaling in traffic:** mediocre but easily fixed. Every request runs
`SELECT * FROM plugins WHERE status='active'`, then for *each* active plugin
does a `file_get_contents(plugin.json)` + JSON decode + `version_compare`
(`PluginLoader.php:56,125`), plus 1–2 `settings` reads per plugin in `boot()`
(`Booking.php:20`, `Membership.php:24`). No APCu/opcache manifest cache. This is
death-by-a-thousand-cuts, not an architectural wall — a boot cache fixes it.

**Scaling in number of modules:** this is the real risk, and today the answer is
**no**. Three structural gaps compound with every module added:

1. **Fragmented identity** — a person is modeled up to 5 ways (`customers`,
   `booking_customers`, `shop_customers`, `restaurant_customers`,
   `clientdesk_clients`), email-keyed with no enforced FK. Every new vertical
   (CRM especially) makes this worse.
2. **No module contracts** — 19 plugins wired by `class_exists('BookingAPI')`
   on concrete global classes (`CoachingAPI.php:48`), with cross-plugin *table
   ownership* collisions (`booking`, `booking-plus`, `restaurant` all `CREATE
   TABLE IF NOT EXISTS booking_*`). Install order decides schema; uninstalling
   one plugin can drop another's tables.
3. **No shared rendering/theme layer** — 6 subsystems each hand-roll an HTML
   document across **5 incompatible token vocabularies** (`--accent/--glass`,
   `--cb-*`, `--sb-*`, landing, storefront). The Theme Engine / Page Builder
   vision cannot sit on this as-is.

Plus data-plane fragility (manual per-query tenant scoping = latent
cross-tenant leaks; no migration framework; money stored as DECIMAL in `shop`
but integer cents everywhere else) and engineering hygiene gaps (no VCS, no
tests, no CI, no autoloader/namespaces).

---

## 2. What to refactor *before* adding more modules (priority order)

| # | Refactor | Why it must come first |
|---|---|---|
| 1 | **Unify the identity/customer model** into a core Contacts service | Every future module (CRM/LMS/SaaS/membership) is identity-centric. Each new plugin that invents its own contact table makes this unfixable. Cheapest to fix now, at 5 tables. |
| 2 | **Introduce a real migration framework** + a data-access layer with automatic tenant scoping | Self-heal `ensureSchema()` can't do data migrations or renames, and manual `AND tenant_id = ?` is already inconsistent (`BookingAPI.php:314,381` scope; `Booking.php:220-228` don't). Every module written before this inherits the risk. |
| 3 | **PSR-4 autoloader + namespaces** | 691 `require_once`, zero namespaces, all classes global — this is *why* coupling is `class_exists`-based. Retrofitting namespaces after 50 plugins is brutal; now it's mechanical. |
| 4 | **Module contract + service registry** (interfaces, versioned deps, activation ordering) | Replaces brittle `class_exists` edges and the unenforced `works_better_with` hint; makes the shop↔stripe bidirectional coupling a clean `PaymentGateway` interface. |
| 5 | **Consolidate rendering** onto one token system + one component kit + `BlockRegistry` as the kernel render primitive | Prerequisite for Theme Engine, Layout System, and Page Builder. Building the Page Builder before this means rebuilding it after. |

Everything else (perf caching, settings UI, CLI scaffolding) can safely wait.

---

## 3. Which core areas to strengthen first

1. **Identity / Contacts** — promote to a first-class kernel service (§2.1).
2. **Data access & tenancy** — a repository/query layer that injects
   `tenant_id` automatically so authors *can't* forget it.
3. **Events & contracts** — namespaced, catalogued hooks/events; a service
   registry so modules resolve capabilities, not classes.
4. **Rendering** — one design-token layer feeding both admin and public.
5. **The meta-layer (non-negotiable for a 10–15yr horizon):** version control,
   an automated test harness, CI, and a migration system. Right now there is
   *no git repo in the tree* — the single highest-leverage thing to fix today.

---

## 4. How Theme Engine / Content Builder / Layout / Component / Page Builder fit

Today these overlap and duplicate. The fix is to define them as **one layered
stack**, each layer depending only on the one below:

```
┌─ Page Builder        the editor UI (drag/drop) that authors Layouts from Blocks
├─ Theme Engine        token sets + header/footer chrome that SKIN everything
├─ Layout / Template    first-class objects (page = ordered sections of blocks)
├─ Block Registry      content-builder's BlockRegistry/Renderer (field-schema → render)
├─ Component Library   server-render UI primitives (buttons, cards, fields) — ONE kit
└─ Design Tokens       ONE custom-property vocabulary, shared admin + public
```

**What each layer becomes, given what exists:**

- **Design Tokens (foundation):** collapse the 5 vocabularies (`--accent/--glass`
  in `ui_components.php:467`, `--cb-*` in `Branding.php:110`, `--sb-*`, landing's
  `--accent/--radius`, storefront's `--bg/--surface`) into **one namespace** that
  both admin and public consume. Accent colour is currently defined independently
  in ≥5 places — that becomes one source of truth.
- **Component Library:** today there are **three parallel admin kits**
  (`ui_components.php` `.slate/.card`, `record_editor.php` `.pv-*`,
  `portal_ui.php`). Merge into one component vocabulary built on the tokens.
- **Block Registry (the spine):** `content-builder/lib/BlockRegistry.php` +
  `Renderer.php` is already the right primitive (field schemas + PHP template or
  callback). **Promote it to core.** Two cleanups: (a) pull the vertical `rx-*`
  restaurant blocks *out* of the core registry (`BlockRegistry.php:234-388`) into
  the restaurant plugin; (b) resolve the fact that `small-business-kit` registers
  a *parallel* `sb-*` block/theme/chrome system into the same registry — on an
  SBK-active site, CB's chrome and SBK's chrome both render. Pick one chrome
  owner.
- **Layout/Template:** currently "layout" is just a flat JSON array of blocks
  (`Renderer::render(?array $layout)`), and there's a legacy duplicate resolver
  (`content-builder/public/render.php` vs `router.php`). Make **Section** and
  **Template** first-class objects so a page is *ordered sections of blocks*, not
  a flat list — this is what a Page Builder needs to manipulate.
- **Theme Engine:** absorbs `Branding.php`'s palettes/font-pairings and the
  header/footer preset libraries (`Theme.php:89,102`) and applies them across
  admin, landing, content-builder pages, **and** the shop storefront (which today
  hardcodes its own cream palette and pulls its own Google Fonts —
  `storefront/includes/layout.php:172,185`).
- **Page Builder:** built **last**, as the editor UI over Blocks + Layouts. Build
  it before the layers below are consolidated and you will rebuild it.

**Key principle:** admin UI and public UI should draw from the *same* token +
component base. Right now they share nothing, which is why there are 5 theme
systems.

---

## 5. How to organize modules so they're independent and reusable

Define a **Module contract** and enforce it:

1. **Own your namespace and your tables — nothing else.** PSR-4 (`Slate\Modules\Booking\…`).
   A module may only create/alter tables it owns; the kernel already blocks
   plugins from touching *core* tables (`PluginLoader.php:594`) — extend that to
   block touching *other plugins'* tables (today booking/booking-plus/restaurant
   violate this).
2. **Depend on interfaces, not classes.** Instead of `class_exists('BookingAPI')`,
   a module requests `kernel.get(PaymentGateway::class)` or listens for a
   documented event. Concrete class names stop being the coupling surface.
3. **Declare dependencies in the manifest, with version constraints**, and let
   the kernel resolve activation order and refuse activation when a required
   capability is missing (replacing the advisory-only `works_better_with`).
4. **Consume shared concepts from the kernel:** identity (Contacts), money
   (one representation — integer minor units everywhere; `shop`'s DECIMAL is the
   outlier that must convert on every Stripe handoff), settings (one accessor,
   one prefix rule), media (already core), rendering (Block Registry).
5. **Capability interfaces (the "Module SDK"):** `PaymentGateway`,
   `IdentityProvider`, `BlockProvider`, `ShippingRateProvider`,
   `NotificationChannel`, etc. A module *provides* or *consumes* capabilities;
   the kernel brokers them. This is how you get true plug-and-play.

---

## 6. Plugin API & developer-experience improvements

- **PSR-4 autoload + namespaces** (kills the global-class collision surface).
- **Declarative manifest wiring.** Today nav/routes/widgets/cron are all
  registered imperatively inside `boot()` (`Booking.php:39,135,44`). Move the
  static parts to the manifest as *data* so the kernel can introspect them
  (build the nav without booting every plugin, list all routes, etc.).
- **Typed settings schema** → auto-generated settings UI, instead of every
  plugin hand-rolling a settings page and ad-hoc `settings` keys (and fix the
  drift: `shop-emails` uses a `shop_email.` prefix that doesn't match its slug;
  `seo` bypasses the core `settings` table entirely with its own `seo_settings`).
- **Migrations, not `ensureSchema()`** — versioned, ordered, reversible, able to
  do data transforms and renames.
- **Dependency resolver + activation ordering** (§5.3), and remove the dead
  permission-registration loop in `activate()` (`PluginLoader.php:422-433`).
- **A hook/event catalogue** — namespaced event names with documented payloads,
  discoverable in a dev inspector, instead of bare global strings.
- **Scaffolding CLI** (`bin/make-module`) that emits a compliant skeleton.
- **A dev mode** (display_errors on, route/hook/event inspector) distinct from
  production.

---

## 7. Decisions today that become problems as the platform grows

| Decision | Why it bites later | Evidence |
|---|---|---|
| Global namespace + `class_exists` coupling | Name collisions inevitable; no versioned contracts | `CoachingAPI.php:48`, no `namespace` anywhere |
| Fragmented customer model | Cross-module identity impossible; CRM can't unify a person | 5 person tables, email-keyed |
| Cross-plugin table ownership under `IF NOT EXISTS` | Install order decides schema; uninstall drops another module's data | `booking`/`booking-plus`/`restaurant` share `booking_*` |
| Manual per-query tenant scoping | One forgotten predicate = cross-tenant leak | `BookingAPI.php:314,381` vs `Booking.php:220-228` |
| `ensureSchema()` instead of migrations | Can't rename/transform; runs on hot path | 7 plugins define it; `Membership.php:179` runs it every dashboard load |
| Money as DECIMAL in shop, cents elsewhere | Rounding risk at every shop↔Stripe boundary | `shop/install.sql:15-17` vs `stripe-payment/install.sql:39` |
| Settings-by-convention, two accessors | Drift already happening | `Plugin::setting()` vs `Database::setting()`; shop-emails/seo deviate |
| Per-request uncached boot | Linear slowdown as plugins grow | `PluginLoader.php:56,125` |
| Verticals baked into core | Core bloats with domain concepts | `rx-*` blocks in `BlockRegistry.php:234-388` |
| No VCS / tests / CI | No safety net for any of the above refactors | no `.git`, no test suite |

---

## 8. If designing Slate for the next 10–15 years — what to change now

1. **Adopt an explicit kernel/module split.** The kernel owns identity, money,
   tenancy, data access, events, and rendering. Modules are guests that depend on
   kernel *contracts*, never on each other's classes or tables.
2. **One identity model.** A person is one row, everywhere.
3. **One render pipeline.** One token vocabulary → one component kit → one block
   registry → layouts/templates → theme engine → page builder.
4. **Contracts with versions.** Capability interfaces + a dependency resolver;
   semver the Module SDK so third parties can build against a stable surface.
5. **Migrations, namespaces/autoload, version control, tests, CI** — the
   non-negotiable substrate. Start with `git init` *today*.
6. **A governance rule going forward:** no new feature ships as an independent
   island. Every module targets a kernel service, or the service doesn't exist
   yet and you build *that* first.

---

## 9. Suggested phased roadmap (sequenced by "gets harder later")

- **Phase 0 — Safety net (do immediately, days).** `git init` + first commit;
  a smoke-test harness (even a handful of HTTP/CLI assertions); a CI hook. This
  is the prerequisite for safely doing everything below.
- **Phase 1 — Kernel substrate (invisible, foundational).** PSR-4 + namespaces;
  a migration framework; a data-access layer that auto-scopes `tenant_id`.
  Nothing user-facing; everything rides on it.
- **Phase 2 — Identity unification.** Core Contacts service; migrate
  `booking_customers`/`shop_customers`/`restaurant_customers`/`clientdesk_clients`
  to link to (and merge on) one identity. Highest value, hardest-if-deferred.
- **Phase 3 — Module contracts.** Service registry + capability interfaces
  (`PaymentGateway` first — it decouples shop↔stripe); dependency resolver +
  activation ordering; enforce table-ownership isolation. Standardize money on
  integer minor units and convert `shop`.
- **Phase 4 — Render/Theme unification.** One token system; merge the three
  admin component kits; promote `BlockRegistry` to core and evict `rx-*`/resolve
  `sb-*`; make Section/Template first-class. **Then** build the Page Builder on
  top.
- **Phase 5 — Performance & scale.** Boot/manifest cache (kill per-request
  `plugin.json` reads); settings cache; move `ensureSchema()` off the hot path.

---

## 10. What NOT to do

- **Don't rewrite from scratch.** The hook system, tenancy columns, centralized
  Stripe, and `BlockRegistry` are worth keeping. This is refactor-in-place.
- **Don't build the Page Builder first.** It's the top of the stack; consolidate
  the render/token/block layers under it first (§4).
- **Don't add the CRM / LMS / SaaS verticals yet.** They are the biggest
  consumers of the identity and contract layers that don't exist — build the
  spine (Phases 1–3), then they become straightforward.
- **Don't drop the legacy contact-form tables or do any destructive migration
  before Phase 0** — there is no version control to recover from a mistake.
