# Current Implementation — Architecture Mapping

**Status:** Living reference · **Purpose:** the migration bridge.

This maps **every major current component to its future replacement**, the
**phase** it migrates in, and the **compatibility mechanism** that keeps the old
one working meanwhile. Its job is to prevent two failure modes: (1) someone
*removing* a legacy component before its replacement is at parity, and (2) someone
*building new work on* a legacy component that's about to be replaced.

> **How to read a row.** During its phase, both the *current* and *future* exist;
> the *compatibility mechanism* bridges them; the legacy piece is retired only per
> the [Compatibility Matrix](compatibility-matrix.md) **after** parity + validation.
> Phases are defined in [09-Roadmap](../09-Roadmap/refactor-roadmap.md); target
> shapes in the [Foundation Standard](../03-Standards/platform-foundation.md).

---

## 1. Core platform

| Current | Future (target) | Phase | Migration | Compatibility |
|---|---|---|---|---|
| `Database` (static PDO + helpers) | `Slate\Data\Database` + `Repository`/`QueryBuilder` (auto tenant scope) | 1 (class move) → 3 (repositories) | A: move+alias · B: introduce repositories alongside raw calls | `class_alias('Database', …)`; raw `Database::` keeps working |
| `includes/*` global classes (no autoload) | `Slate\*` PSR-4 under `src/` | 1 (A1–An) | Move one class per commit | Hand-rolled PSR-4 loader **+** `require_once` coexist; `class_alias` per class |
| `PluginLoader` | `Slate\Kernel\Module\ModuleManager` + dependency resolver | 1 (move/alias) → 3 (contracts) | Rename via alias P1; add resolver + capability graph P3 | `class_alias('PluginLoader', …)`; adapter keeps `isActive()`/boot API |
| `Plugin` (base) | `Slate\Sdk\Module` | 1 (alias) → 3 (SDK) | Alias P1; modules extend `Sdk\Module` as rebuilt | `class_alias('Plugin', …)` |
| `Hook` (global-string actions/filters) | `Slate\Kernel\Event\EventBus` (typed events) + extension points | 1 (move) → 3 (typed catalogue) | Move+alias P1; typed event classes added alongside string hooks P3 | String hooks keep firing; typed bus wraps them |
| `ensureSchema()` self-heal | `Slate\Data\Migration` + runner + ledger | 1 (core) → 3 (plugins) | Capture current schema as `0001_init`; runner stamps baseline | `ensureSchema` left untouched until each plugin adopts migrations |
| Manual `AND tenant_id = ?` | `Slate\Tenancy\TenantContext` + auto-scoping `Repository` | 1 (introduce) → ongoing | New code uses repositories; old queries unchanged | Additive; `current_tenant_id()` preserved |
| `config.php` bootstrap | `Slate\Kernel\Bootstrap` + front controller | 1 → 2 | Front controller added alongside; admin pages migrate behind it | Old entry points keep working until parity |

## 2. Identity & data

| Current | Future | Phase | Migration | Compatibility |
|---|---|---|---|---|
| `customers` table + `customer_auth_tokens` | `Slate\Domain\Identity\Contact` + `identities` + `Slate\Services\Identity\IdentityStore` | 2 | Introduce `contacts`; dual-write; backfill | `customers` kept + synced during window; views/shims for readers |
| `booking_customers`, `shop_customers`, `restaurant_customers`, `clientdesk_clients` | Module **profiles** keyed by `contact_id` | 2 | Match on email → link to one Contact; migrate profile columns | Legacy tables retained read-only during window; FK backfilled |
| Money as `DECIMAL` (shop) vs `*_cents` | `Slate\Support\Money` (integer minor units) everywhere | 3 | Convert shop columns to minor units; hydrate to `Money` | Conversion at boundary until columns migrated |

## 3. Services

| Current | Future | Phase | Migration | Compatibility |
|---|---|---|---|---|
| `Auth` (admin + customer flows) | `Slate\Services\Auth\Auth` + one `Authenticator` (pluggable providers) | 1 (move) → 3 (unify) | Move+alias P1; unify flows behind one authenticator P3 | `class_alias('Auth', …)`; existing sessions honored |
| RBAC in `Auth::can()` | `Slate\Services\Rbac` policy engine (`can(principal,perm,resource)`) | 3 | Extract policy engine; keep permission keys | Same `<domain>.<action>` keys; `Auth::can()` delegates |
| `Settings` (key/value, 2 accessors) | `Slate\Services\Settings` (typed schema) | 1 (move) → 3 (typed) | Move; add typed manifest settings alongside | Both accessors preserved; keys unchanged |
| `Mailer` + synchronous sends | `Slate\Services\Notifications` (`NotificationChannel`, queued) | 3 | Channel drivers; events → queued sends | Direct `Mailer` calls keep working until migrated |
| `Media` (core) + `media-library` shim | `Slate\Services\Media` | 1 (move) → keep shim | Move+alias; shim stays required | `class_alias('Media', …)`; shim untouched |
| `seo` plugin (own `seo_settings`) | `Slate\Services\Seo` (`SeoMetaProvider`, sitemap) | 4 | Promote to service; entities provide meta | Plugin hooks kept until service parity |
| Ad-hoc search (`LIKE`) | `Slate\Services\Search` (`SearchIndex`, FULLTEXT default) | 3 | Introduce index + contract; modules index entities | Additive; existing search paths unchanged |
| `I18n` + `lang_overrides` | `Slate\Services\I18n` | 1 (move) | Move+alias | `class_alias('I18n', …)`; overrides preserved |
| `AuditLog` | `Slate\Services\Audit` | 1 (move) | Move+alias | `class_alias('AuditLog', …)` |
| — (no cache/queue abstraction) | `Slate\Contracts\Cache`, `\Queue` (file/DB default) | 3–4 | Introduce interfaces + default drivers | Additive |

## 4. Modules (verticals)

| Current | Future | Phase | Migration | Compatibility |
|---|---|---|---|---|
| `BookingAPI` (global) | `Slate\Module\Booking` (`BookingService` + repositories) on Contacts/Money/Payments | 3 | Rebuild on SDK + contracts; move `booking_*` into the module (ownership already sole) | `class_alias('BookingAPI', …)` until callers move to `booking@1` |
| `ShopAPI` (global, DECIMAL) | `Slate\Module\Shop` (Money-based; `PaymentGateway`) | 3 | Rebuild on SDK; decouple from stripe; convert money | `class_alias('ShopAPI', …)`; storefront preserved |
| `MembershipAPI` (reuses `customer_id`) | `Slate\Module\Membership` (`membership@1`) | 3 | Rebuild on SDK; consume Identity/Payments/`booking@1` | `class_alias`; integration filters preserved |
| `StripePaymentAPI` (+ shop coupling) | `Slate\Services\Payments` (`PaymentGateway`) — **decoupled** | 3 | Extract gateway; consumers reconcile via `payment.succeeded` | `class_alias('StripePaymentAPI', …)` until consumers move to the contract |
| `content-builder` (`ContentBuilderAPI`) | `Slate\Module\WebsiteCms` on the core render stack | 4 | Promote `BlockRegistry`/`Renderer` to core; move `rx-*`/`sb-*` out | Plugin keeps working until CMS module parity |
| Forms plugin + legacy core contact-forms | `Slate\Module\Forms` (one system) | 3 | Migrate legacy submissions; retire legacy tables (with sign-off) | Both run until migration; legacy nav already hidden |
| coaching / clientdesk / restaurant / others | `Slate\Module\*` on the spine | 3+ | Rebuild per module as capacity allows | `class_alias` per `*API` |

## 5. Presentation

| Current | Future | Phase | Migration | Compatibility |
|---|---|---|---|---|
| `content-builder/lib/BlockRegistry` + `Renderer` | Core `Slate\Presentation\Blocks` (Block Registry + Renderer) | 4 | Promote to core; register via `blocks.register`; evict `rx-*`, resolve `sb-*` | Plugin registry aliased/bridged to core during promotion |
| `content-builder/lib/Theme.php` + `Branding.php` | `Slate\Presentation\Theme` (Theme Engine) + `Templates` | 4 | Absorb palettes/font-pairings/chrome presets; add Section/Template objects | CB theme kept until engine parity |
| Flat JSON block array ("layout") | First-class `Section` + `Template` + `Page` | 4 | Wrap existing block arrays as single-section pages; add editor | Old page JSON reads as one default section |
| 5 token vocabularies (`--accent/--glass`, `--cb-*`, `--sb-*`, landing, storefront) | One `--slate-*` vocabulary | 4 | Consolidate; map old → new tokens | Old tokens shimmed to new values during transition |
| 3 admin component kits (`ui_components`, `record_editor` `.pv-*`, `portal_ui`) | One `Slate\Presentation\Components` library | 4 | Merge into one component set on the token layer | Old classes retained until callers migrate |
| Visual builder (none; up/down reorder) | `Slate\Presentation\PageBuilder` | 4 (v2.x) | Build editor over Sections/Blocks | N/A (new) |

---

## Retirement principle

No row's *current* side is deleted until the [Compatibility Matrix](compatibility-matrix.md)
marks it **retired** — which requires: replacement at parity, validation against
[load-bearing-behaviors.md](load-bearing-behaviors.md) + smoke suite, and (for
data) a completed migration with a dual-write/backfill window. Compatibility
mechanisms (`class_alias`, wrappers, adapters, views) are themselves a **covered
surface** removed only in a future major ([versioning-and-compatibility.md](../03-Standards/versioning-and-compatibility.md)).
