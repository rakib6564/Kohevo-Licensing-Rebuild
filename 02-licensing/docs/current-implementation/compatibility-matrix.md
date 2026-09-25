# Current Implementation — Compatibility Matrix

**Status:** Living reference · **Purpose:** track every compatibility bridge and
its retirement.

A companion to [architecture-mapping.md](architecture-mapping.md). Where the
mapping explains *what becomes what*, this table tracks the **live compatibility
mechanisms** so none is removed prematurely. **A compatibility layer is retired
only when its "Retire when" condition is fully met** — and every removal is a
deliberate, reviewed change (a future major per
[versioning-and-compatibility.md](../03-Standards/versioning-and-compatibility.md)).

**Legend — Strategy:** *Wrapper* (new code wraps old) · *Alias* (`class_alias`) ·
*Adapter* (new API adapts to old callers) · *Migration* (data moved) · *Promotion*
(plugin code moved to core) · *Bridge* (both run, synced).
**Status:** `planned` · `active` (bridge in place) · `retired`.

---

## Core & kernel

| Current | Future | Phase | Strategy | Compat mechanism | Retire when | Status |
|---|---|---|---|---|---|---|
| `Database` | `Slate\Data\Database` | 1 | Alias | `class_alias('Database',…)` | never (public name kept ≥ next major) | planned |
| `Database::query` raw calls | `Repository`/`QueryBuilder` | 3 | Wrapper | raw calls delegate to new layer | all call-sites migrated + tenant-scoped | planned |
| `PluginLoader` | `ModuleManager` | 1→3 | Alias + Adapter | `class_alias`; adapter preserves `isActive()`/boot | all callers use ModuleManager API | planned |
| `Plugin` base | `Sdk\Module` | 1→3 | Alias | `class_alias('Plugin',…)` | all modules extend `Sdk\Module` | planned |
| `Hook` string hooks | `EventBus` typed events | 1→3 | Bridge | typed bus emits+listens to string hooks | all listeners typed + catalogue frozen | planned |
| `ensureSchema()` | Migration runner | 1→3 | Bridge | runner owns core; `ensureSchema` owns plugins until adopted | plugin ships migrations | planned |
| 691 `require_once` | PSR-4 autoload | 1 | Bridge | loader + `require_once` coexist | class lives in `src/` + aliased | planned |
| `config.php` entry | Front controller | 1→2 | Adapter | old entry points keep working | all routes behind front controller | planned |

## Identity & data

| Current | Future | Phase | Strategy | Compat mechanism | Retire when | Status |
|---|---|---|---|---|---|---|
| `customers` table | `contacts` + `identities` | 2 | Migration + Bridge | dual-write + backfill; `customers` synced | backfill complete + readers moved | planned |
| `booking_customers` etc. (5 tables) | Contact **profiles** (`contact_id`) | 2 | Migration | email-match → link; migrate profile cols | FK backfilled + module reads Contact | planned |
| `customer_auth_tokens` | `identities` auth linkage | 2 | Migration | reissue flow reads new store | portal auth moved | planned |
| shop `DECIMAL` money | `Money` (minor units) | 3 | Migration + Wrapper | convert at boundary until columns migrated | shop columns are integer minor units | planned |

## Services

| Current | Future | Phase | Strategy | Compat mechanism | Retire when | Status |
|---|---|---|---|---|---|---|
| `Auth` | `Services\Auth\Auth` | 1 | Alias | `class_alias('Auth',…)` | ≥ next major | planned |
| `Auth::can()` RBAC | `Rbac` policy engine | 3 | Wrapper | `Auth::can()` delegates to engine | all authz via policy engine | planned |
| `Settings` (2 accessors) | `Services\Settings` (typed) | 1→3 | Alias + Adapter | both accessors kept; keys unchanged | typed schema adopted; keys unchanged | planned |
| `Mailer` sync sends | `Notifications` (`NotificationChannel`) | 3 | Wrapper | `Mailer` calls route to channel/queue | senders emit events | planned |
| `Media` + `media-library` shim | `Services\Media` | 1 | Alias | `class_alias('Media',…)`; **shim stays** | shim retired only when adopters migrate | active (shim) |
| `I18n` / `AuditLog` | `Services\I18n` / `Services\Audit` | 1 | Alias | `class_alias(…)` | ≥ next major | planned |
| `seo` plugin | `Services\Seo` | 4 | Promotion | plugin hooks kept until parity | service at parity | planned |

## Modules

| Current | Future | Phase | Strategy | Compat mechanism | Retire when | Status |
|---|---|---|---|---|---|---|
| `ShopAPI` | `Module\Shop` | 3 | Alias + Wrapper | `class_alias('ShopAPI',…)` | callers use `catalog`/events | planned |
| `BookingAPI` | `Module\Booking` (`booking@1`) | 3 | Alias | `class_alias('BookingAPI',…)` | callers use `booking@1` | planned |
| `MembershipAPI` | `Module\Membership` (`membership@1`) | 3 | Alias | `class_alias` | callers use `membership@1` | planned |
| `StripePaymentAPI` (+ shop coupling) | `Services\Payments` (`PaymentGateway`) | 3 | Alias + Adapter | alias; consumers reconcile via `payment.succeeded` | shop↔stripe coupling removed | planned |
| Legacy core contact-forms | `Module\Forms` | 3 | Migration | both run; legacy nav hidden | legacy submissions migrated | active (deprecated) |
| Two shipping plugins | one `ShippingRateProvider` | 3 | Adapter | conflict notice; run one | single capability provider | active (guarded) |

## Presentation

| Current | Future | Phase | Strategy | Compat mechanism | Retire when | Status |
|---|---|---|---|---|---|---|
| CB `BlockRegistry`/`Renderer` | Core `Presentation\Blocks` | 4 | Promotion | plugin registry bridged to core | core registry is source of truth | planned |
| `rx-*` blocks in core registry | restaurant module blocks | 4 | Migration | move to owning module | evicted from core | planned |
| `sb-*` SBK parallel system | one block/theme/chrome | 4 | Migration | pick one chrome owner | SBK parallel removed | planned |
| CB `Theme.php`/`Branding.php` | `Presentation\Theme` engine | 4 | Promotion | CB theme kept until parity | engine at parity | planned |
| flat JSON "layout" | `Section`/`Template`/`Page` | 4 | Wrapper | old JSON reads as one default section | pages authored as sections | planned |
| 5 token vocabularies | one `--slate-*` | 4 | Bridge | old tokens shimmed to new values | callers use `--slate-*` | planned |
| 3 admin component kits | one Component Library | 4 | Bridge | old classes retained | callers migrated | planned |

---

## Rules for this matrix

1. **Never delete a bridge whose "Retire when" is unmet.** If unsure, it stays.
2. **Update this table in the same commit** that adds or retires a bridge
   (amend-first). A retired row moves to a "Retired" archive section, never
   deleted, with the commit/date.
3. **`class_alias` bridges are collectively a covered surface** — they are the
   promise that keeps installed plugins working, removed only in a planned major.
4. **Every "Status: active" bridge is load-bearing right now** — see
   [load-bearing-behaviors.md](load-bearing-behaviors.md).
