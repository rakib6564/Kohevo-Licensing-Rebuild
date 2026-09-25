# Current Implementation — Plugin System (As-Built)

**Status:** Living reference · **Describes:** `includes/PluginLoader.php`,
`includes/Plugin.php`, and how plugins actually load today.

How the current plugin system works end to end. The target
([../01-Architecture/plugin-architecture.md](../01-Architecture/plugin-architecture.md))
replaces this with a Module Manager + dependency resolver; this documents what
Phase 1/3 must preserve and evolve.

---

## 1. The registry is the source of truth

The `plugins` table (`slug`, `version`, `status`, `manifest_json`, timestamps)
decides everything. `status='active'` = boot; anything else = skip.
`PluginLoader::boot()` reads it **on every request**.

## 2. Lifecycle (as-built)

| Phase | Trigger | What happens |
|---|---|---|
| **Upload** | ZIP via `admin/plugins.php` | staged cross-filesystem-safe; manifest + SQL validated |
| **Install** | after upload | row inserted `status='installed'`; files placed under `plugins/<slug>/` |
| **Activate** | admin action | `install.sql` executed (creates the plugin's tables); `status='active'`; `activated_at` set. **The `activate()` permission-registration loop is a dead no-op** (`PluginLoader.php` ~:422–433) — permissions actually come from `manifest_json` via `Auth::knownPermissions()` |
| **Boot** | every request (active only) | `PluginLoader::boot()` — see §3 |
| **Runtime** | per request | the plugin's registered hooks/routes/widgets fire as the shell dispatches |
| **Upgrade** | file `version` > DB `version` | `loadOne()` detects via `version_compare` and `UPDATE`s the row; the plugin's own `ensureSchema()` reconciles columns on next boot |
| **Deactivate** | admin action | `status='inactive'`; `deactivated_at` set; **data retained** (tables kept) |
| **Uninstall** | admin action | `uninstall.sql` runs (drops **only** the plugin's own-prefix tables — verified); files removed |

## 3. Boot sequence (`PluginLoader::boot()`)

Called once per request from `config.php` (guarded by a static `$booted` flag):

1. `SELECT * FROM plugins WHERE status='active' ORDER BY id ASC` — **load order is
   install order (row id), NOT dependency order.**
2. For each active plugin, `loadOne()`:
   a. read `plugins/<slug>/plugin.json` from disk; `version_compare` against the DB
      `manifest_json`; if the file is newer, `UPDATE` the row (a `file_get_contents`
      + JSON decode **per plugin per request**);
   b. derive the class from the slug (`slugToClass()`: `booking`→`Booking`,
      `content-builder`→`ContentBuilder`);
   c. `require_once plugins/<slug>/<Class>.php`;
   d. construct it and call **`boot()`**.
3. Boot is **exception-isolated** — a plugin that throws in `boot()` is caught and
   logged; the shell and other plugins continue.
4. A soft guardrail logs boots over `MAX_BOOT_MS = 250` (logged, not prevented).

**Cost per request:** 1 `plugins` SELECT + N × (`plugin.json` read + JSON decode +
`version_compare`) + each plugin's 1–2 `settings` reads in `boot()`. **No boot
cache.** This is the #1 steady-state perf item ([technical-debt.md](technical-debt.md) S9).

## 4. Registration is imperative (in `boot()`)

The manifest is declarative only for **identity, permissions, and soft-deps**.
Everything else is registered imperatively via `Hook` calls inside `boot()`:

```php
public function boot(): void {
    Hook::addFilter('admin_nav_items',        [$this, 'addAdminNav']);
    Hook::addFilter('public_routes',          [$this, 'routes']);
    Hook::addFilter('admin_dashboard_widgets',[$this, 'dashboardWidget']);
    Hook::addAction('frequent_cron',          [$this, 'sendReminders']);
    // blocks: direct BlockRegistry::register + a content_register_blocks fallback
}
```

- **Nav** items are arrays (`slug/label/href/icon/perm/order/group`) consumed by
  `admin/partials/header.php`.
- **Routes** return `['<prefix>' => ['handler'=>…, 'methods'=>[…]]]`, consumed by
  `PublicRouter::dispatch()`.
- **Dashboard widgets** return HTML strings (via `ob_start`).
- **Cron** handlers subscribe to `frequent_cron`/`daily_cron`.
- **Blocks** register both directly against the global `BlockRegistry` and via a
  `content_register_blocks` fallback (order-independent).

Because registration requires *executing* `boot()`, the shell must boot every
plugin just to know what routes/nav/widgets exist — the reason the target moves
static wiring into the manifest + a boot cache.

## 5. Manifest (`plugin.json`) — as-built shape

Keys actually used across the 19 manifests (verified):

| Key | Role | Enforced? |
|---|---|---|
| `slug`, `name`, `version`, `description`, `author` | identity | — |
| `requires_core` | core-version constraint (e.g. `>=1.0.0`) | **yes** (at install) |
| `permissions[]` | `{key,label}` objects, `<domain>.<action>` | surfaced via `Auth::knownPermissions()` |
| `author_url` | link | no |
| `works_better_with[]` | soft inter-plugin hint | **no — advisory only, never read at boot** |
| `system` | (one plugin) marks a system plugin | — |

**There is no `requires`/`provides` capability system and no inter-plugin version
constraint.** `works_better_with` is validated as a known key and otherwise
ignored. A consumer whose provider is inactive just fails its own `class_exists`
guard and degrades.

## 6. Dependency handling (as-built)

- **None enforced.** Cross-plugin needs are `class_exists('<X>API')` +
  `PluginLoader::isActive('<slug>')` guards; absent → graceful early-return.
- **Load/activation order is install order**, so a consumer *can* boot before its
  provider — but since coupling is lazy (`class_exists` at call time, not boot
  time), this usually works out. It is nonetheless non-deterministic.
- The full edge list is in [modules-as-built.md](modules-as-built.md).

## 7. Packaging

`bin/package-plugin.php` validates the manifest + SQL before zipping (a ZIP that
passes is guaranteed to install). Pre-built ZIPs live in `plugins/_dist/`
(git-ignored). `bin/` also has `seed-demo.php` / `clean-demo.php`.

## 8. What Phase 1/3 changes (preserving this)

- **Phase 1:** move `PluginLoader`/`Plugin` into `Slate\Kernel\Module\*` +
  `Slate\Sdk\Module` behind `class_alias` (names keep working); introduce the boot
  cache; migrations coexist with `ensureSchema`.
- **Phase 3:** manifest v2 (`provides`/`requires` + version constraints), a
  dependency resolver with topological activation order, declarative wiring, and
  table-ownership enforcement — all additively, plugins migrating one at a time.

---

## Related

- [runtime-catalogues.md](runtime-catalogues.md) · [modules-as-built.md](modules-as-built.md) · [technical-debt.md](technical-debt.md)
- Target: [../01-Architecture/plugin-architecture.md](../01-Architecture/plugin-architecture.md) · [../06-SDK/manifest.md](../06-SDK/manifest.md)
