# Design Review — `PluginLoader`

**Source:** `includes/PluginLoader.php` (740 lines) · **Callers:** 34 files · **No code.**

The class that **drives boot**. Second SRP violation (six responsibilities) and the
**trickiest qualification** of the four. Migrate **last**, **whole**, with the most
verification.

---

## 1. Current implementation

**Responsibilities (6)**
1. **Boot / registry** — `boot` (reads active plugins, requires bootstrap, calls
   `->boot()`, MAX_BOOT_MS warn), `loadOne`, `isActive`, `activePlugins`, `get`,
   `listAll`.
2. **Lifecycle** — `install` (ZIP), `activate` (run install.sql, status), `deactivate`,
   `uninstall` (run uninstall.sql, remove files).
3. **Asset rendering** — `assetVersion`, `renderQueuedStyles`, `renderQueuedScripts`
   (emit `<link>`/`<script>` with mtime cache-busting).
4. **Manifest / SQL validation** — `validateManifest` (required fields, slug regex,
   semver, allowed-keys), `validatePluginSql` (banned patterns, table-prefix check),
   `slugToClass`.
5. **SQL execution** — `executeSqlFile` (naive `;`-split, per-statement exec).
6. **Filesystem operations** — ZIP extraction/staging, `moveDir` (rename + EXDEV
   copy fallback), `copyTree`, `rmrf` (root-guarded recursive delete).

**Public API** — `boot` (3), `isActive` (32 — by far the most-used),
`install`/`activate`/`deactivate`/`uninstall` (admin), `listAll`, `activePlugins`,
`get`, `renderQueuedStyles`/`renderQueuedScripts`, `slugToClass`, `validateManifest`,
`validatePluginSql`, and the constants `STATUS_*`.

**Internal dependencies**
- `Database::` — plugins table reads/writes, `get()->exec` for SQL files.
- **`Plugin`** (base class) — `loadOne(): ?Plugin`, `is_subclass_of($className, Plugin::class)`,
  `new $className(...)`, `@var array<string, Plugin>`, `Plugin $plugin` params.
- `Auth::invalidatePermCache()` — called in `activate()` (line 443).
- **`ZipArchive`** (global) — `install()`.
- Globals: `slate_log`, `slate_semver_satisfies`, `e()`, `SLATE_ROOT`, `SLATE_VERSION`,
  fs functions, `set_error_handler`/`restore_error_handler`.

**External callers** — 34 files; **32 of the calls are `isActive`** (plugins
detecting each other), plus `boot` (config.php), the admin plugin-manager
(`install/activate/deactivate/uninstall/listAll`), and the layout
(`renderQueuedStyles/Scripts`).

**Bootstrap order** — `config.php` line 120 calls `PluginLoader::boot()` as the last
core step. `boot()` needs `Database` (query), `Plugin` (base, required by each
plugin bootstrap), and lazily `Auth` (only on `activate`, an admin action — not at
boot).

**Lifecycle** — static registry: `$active` (booted Plugin objects), `$activeSlugs`
(quick lookup), `$booted` (once-guard). `boot()` is idempotent per request;
`activate/deactivate/uninstall` null `$activeSlugs` to force a re-read.

---

## 2. Coupling analysis

- **Who depends on it:** 34 files. The hot path is `isActive` (cross-plugin
  detection); the admin plugin manager owns the lifecycle calls; layout owns asset
  rendering.
- **What it depends on:** `Database`, `Plugin` (base), `Auth` (activate only),
  `ZipArchive`. Ordering implication: **`Auth` must be migrated before `PluginLoader`**
  (satisfied by the recommended order), and **`Plugin` must be loadable** as `\Plugin`.
- **Circular dependency risk:**
  - `config.php → PluginLoader::boot → Database` (fine, Database is up).
  - `PluginLoader::activate → Auth::invalidatePermCache` (fine, admin-time, lazy).
  - `loadOne → new $className extends \Plugin`, and a plugin's `boot()` may call
    `PluginLoader::isActive(...)` on another plugin — **re-entrant** but guarded by
    the `$booted`/`$activeSlugs` flags (isActive triggers boot only if not yet
    booted). No infinite loop; preserve the guard semantics exactly.
- **Hidden assumptions:**
  - **`slugToClass` convention** (`hello-world`→`HelloWorld`, file `HelloWorld.php`,
    class `HelloWorld`) is load-bearing — every plugin bootstrap relies on it.
  - `loadOne` **writes** to the plugins table (persists a refreshed manifest when the
    on-disk version is newer) — a write on the read/boot path; must be preserved.
  - `MAX_BOOT_MS = 250` is a *warning*, not a limit.
  - The **`activate()` permission loop (lines 421–433) is a dead no-op** — it iterates
    and intentionally inserts nothing. **Do not "fix" it into inserting rows** during
    the migration (behavior must stay identical).
  - `validatePluginSql` banned-list + prefix check, and `rmrf`'s allowed-roots guard,
    are **security controls** — preserve verbatim.
  - `executeSqlFile` uses naive `;`-splitting (fine for plugin DDL; no procedures).
- **Global state:** `$active`, `$activeSlugs`, `$booted`. Per-request; the registry a
  future ModuleManager owns.

---

## 3. Migration strategy

- **Namespace / location:** `Slate\Kernel\Module\PluginLoader` →
  `src/Kernel/Module/PluginLoader.php`. *(Migrate whole — do not split; see SRP note.)*
- **Qualification — this is the careful one:**
  - `Database::` → `\Database::`, `Auth::` → `\Auth::` (1 site).
  - **`Plugin` → `\Plugin`** at *specific* sites only — `?Plugin`, `Plugin::class`,
    `Plugin $plugin`, `array<string, Plugin>`. **Do NOT blind-replace `Plugin`** — the
    class name `PluginLoader` contains the substring `Plugin`; a global replace would
    corrupt it. Target each occurrence explicitly.
  - `new ZipArchive()` → `new \ZipArchive()`.
  - `is_subclass_of($className, \Plugin::class)` — the FQCN check must reference the
    global `\Plugin` (the alias), so subclass checks against the base still pass.
  - Everything else (fs functions, `slate_*`, `e`, constants) falls back to global.
- **`Plugin` base class stays global during A3.** `\Plugin` resolves to today's global
  `Plugin` (from `includes/Plugin.php`). Migrating `Plugin` → `Slate\Sdk\Module` is a
  Phase-3 SDK task (it changes what module authors extend), out of scope here.
- **Alias strategy:** `class_alias(\Slate\Kernel\Module\PluginLoader::class, 'PluginLoader');`.
  All 34 callers keep `PluginLoader::…`.
- **Bootstrap considerations:** `config.php` requires `includes/PluginLoader.php`
  (forwarder) then calls `PluginLoader::boot()`. Because `aliases.php` loads first and
  autoloads the new class, the global `PluginLoader` is ready at the `boot()` call.
  **Verify the boot call still executes and boots all active plugins** (the highest-
  value check of the whole migration).
- **Backward compatibility:** total; `isActive` and lifecycle signatures unchanged;
  plugin bootstraps (`extends \Plugin`) unaffected because `\Plugin` is the same class.
- **Risk assessment:** **HIGHEST of the four.** Not code volume — it's that (a) it
  drives boot, (b) the `Plugin` substring hazard, and (c) re-entrancy via `isActive`.
  All are mitigated by careful targeted edits + a full plugin-boot smoke.
- **Rollback strategy:** `git revert` the single commit; additive; no schema/data
  change. If boot breaks, the revert restores the global loader immediately (and the
  site's plugins with it).

---

## 4. Verification plan

- **Existing smoke coverage:** `class_exists('PluginLoader')` asserted; **smoke boots
  `config.php` which calls `PluginLoader::boot()`** — so a broken loader fails smoke
  (no plugins boot → downstream class/table checks may also shift). Strongest
  existing coverage of the four.
- **Additional checks required:**
  - Resolve check: `PluginLoader` → `Slate\Kernel\Module\PluginLoader`.
  - `PluginLoader::isActive('booking')` (and other active slugs) returns `true`;
    `isActive('nope')` returns `false`.
  - `PluginLoader::activePlugins()` returns the 8 active plugin objects, each an
    instance of `\Plugin`.
  - `slugToClass('content-builder') === 'ContentBuilder'`.
  - `validateManifest`/`validatePluginSql` accept a known-good and reject a bad
    manifest/SQL (pure functions — cheap to assert).
- **Runtime verification:** load the **admin plugin manager** page (lists all
  plugins via `listAll`, renders active plugin assets via `renderQueuedStyles/Scripts`);
  load a **public plugin route** (booking `/book`) to confirm active plugins actually
  booted; optionally **deactivate + reactivate** a low-risk plugin to exercise the
  lifecycle + cache invalidation.
- **Edge cases:** an active plugin whose directory is missing (logs + skips, request
  continues), a plugin that throws in `boot()` (isolated + logged), re-entrant
  `isActive` during another plugin's boot, on-disk manifest newer than DB (persists
  refresh).
- **Plugin compatibility:** this **is** the plugin-compatibility test — all 8 active
  plugins must boot and register nav/routes/blocks exactly as before. Confirm a
  cross-plugin `class_exists('BookingAPI')` path (membership → booking) still resolves.

---

## 5. Future evolution (Architecture v1.0)

- **Service Container / Module Manager:** responsibility #1 becomes
  `Slate\Kernel\Module\ModuleManager` with a **dependency resolver** (topological
  activation from `provides`/`requires`), replacing install-order boot and the
  advisory `works_better_with`.
- **Boot cache:** the per-request `plugins` read + `plugin.json` disk read +
  `version_compare` are compiled once into the boot cache
  ([kernel](../../01-Architecture/kernel.md)) — eliminating the #1 steady-state cost.
- **Installer / Packager (extract SRP #2, #4, #5, #6):** ZIP install, manifest/SQL
  validation, SQL execution, and filesystem moves become a separate
  `Packager`/`Installer` collaborator; `executeSqlFile` is superseded by the
  **migration framework**.
- **Asset Manager (extract SRP #3):** `renderQueuedStyles/Scripts` move to the
  **Asset Manager** (content-hash fingerprints instead of mtime).
- **`Plugin` → `Sdk\Module`:** the base class becomes `Slate\Sdk\Module`; manifest v2
  drives declarative wiring; the dead `activate()` perm loop is replaced by the
  manifest-declared permission union.
- **Capability Contracts:** module-to-module detection stops using `isActive` +
  `class_exists` and uses capability resolution from the container.

### SRP note — **VIOLATION (6 responsibilities)**
Document now, split in **Phase 3** (never during A3):
- `Slate\Kernel\Module\ModuleManager` — boot, registry, lifecycle, dependency resolver.
- `Packager`/`Installer` — ZIP + validation + SQL exec + filesystem.
- **Asset Manager** — queued style/script rendering.
Migrate as one aliased class now; the split is deliberate later with its own reviews.

---

## Recommendation

Migrate **last**. Depends on `Auth` (order it after Auth) and the global `\Plugin`.
Treat the **full plugin-boot smoke** (all 8 active plugins boot + a live public
route + the admin plugin manager) as the gate — this migration's blast radius is the
entire plugin ecosystem. Watch the `Plugin`-vs-`PluginLoader` substring hazard:
qualify `Plugin` only at its explicit occurrences.
