# A3 Core-Class Migration — Design Reviews

**Status:** For review · **Phase:** 1 A3 (remaining core classes) · **No code — analysis only.**

The seven leaf classes (`Hook`, `I18n`, `AuditLog`, `Notifications`, `Uploads`,
`Media`, `Mailer`) migrated cleanly as mechanical namespace moves. The remaining
four are **architectural components**, not mechanical refactors. This folder holds
a design review per class before any code is written, so we choose the safest
implementation order and know in advance where each class is headed in
[Architecture v1.0](../../03-Standards/platform-foundation.md).

| Review | Lines | External callers | SRP verdict |
|---|---|---|---|
| [database.md](database.md) | 120 | **179 files** | Mild — a Settings concern rides along; migrate whole, extract later |
| [auth.md](auth.md) | 744 | 160 files | **Violation — 7 responsibilities**; migrate whole now, split in Phase 3 |
| [public-router.md](public-router.md) | 113 | 3 files | Clean — single responsibility |
| [plugin-loader.md](plugin-loader.md) | 740 | 34 files | **Violation — 6 responsibilities**; migrate whole now, split in Phase 3 |

---

## Cross-cutting dependency analysis

What each class depends on **among the classes we still migrate** (this determines
safe ordering):

```
Database     → (nothing in our set)              LEAF. Everything else depends on it.
PublicRouter → Hook                              (Hook already migrated)
Auth         → Database, Hook, AuditLog, Mailer  (all already migrated)
PluginLoader → Database, Auth, Plugin(base), ZipArchive
```

- **`Database` is the dependency leaf** but the **highest blast radius** (179
  callers; every already-migrated class calls `\Database::`). Simple code, maximal
  reach.
- **`PublicRouter` is independent** — needs only `Hook` (done); 3 callers. Lowest
  risk of the four.
- **`Auth`** needs Database + Hook + AuditLog + Mailer — **all already migrated**,
  so nothing blocks it.
- **`PluginLoader`** needs Database + Auth + the `Plugin` base class, and **drives
  boot**, so it must go last and needs Auth done first.

### One dependency not in the four: `Plugin` (base class)

`PluginLoader` type-hints and `is_subclass_of()`-checks the global `Plugin` base
class, and every plugin `extends Plugin`. `Plugin` is **not** one of the four.
Recommendation: **leave `Plugin` global during A3** (PluginLoader references
`\Plugin`); migrating `Plugin` → `Slate\Sdk\Module` is more than a rename (it is the
SDK base class) and belongs to **Phase 3**, done deliberately with the SDK. See
[plugin-loader.md](plugin-loader.md).

---

## Recommended implementation order (revised from the analysis)

The stated order was `Database → Auth → PublicRouter → PluginLoader`. The dependency
+ risk analysis suggests **moving `PublicRouter` first**:

| # | Class | Why here |
|---|---|---|
| 1 | **PublicRouter** | Lowest risk (only `Hook`, 3 callers). A warm-up that de-risks the new `Slate\Kernel\Http` namespace before the heavy classes. |
| 2 | **Database** | The leaf everything depends on. Simple code, but highest blast radius — do carefully, then **re-verify all 7 already-migrated classes** resolve `\Database::` through the alias, plus smoke. |
| 3 | **Auth** | Depends only on already-migrated classes. Mechanical (like `Media`), but larger surface + security-critical paths (throttling, sessions) → extra runtime verification. |
| 4 | **PluginLoader** | Depends on Database + Auth + `Plugin`; **drives boot**. Trickiest qualification (`Plugin`→`\Plugin` without touching `PluginLoader`; `\ZipArchive`). Most scrutiny; full plugin-boot smoke. |

This keeps the trio's relative order (`Database → Auth → PluginLoader`); only
`PublicRouter` moves earlier. Final call is yours.

---

## SRP findings (document-before-changing)

Two classes violate SRP and should be **split in Phase 3** — but **migrated whole
now** (splitting during a namespace move would break the one-class-per-commit,
behavior-identical discipline):

- **`Auth`** → future `Slate\Services\Auth` (Authenticator: sessions + admin +
  customer login + throttling) **+** `Slate\Services\Rbac` (policy engine:
  `can`/`requirePerm`/`corePermissions`/`knownPermissions`) **+** customer
  identity/tokens/verification → **Identity** (Phase 2) & **Notifications**.
- **`PluginLoader`** → future `Slate\Kernel\Module\ModuleManager` (boot, registry,
  lifecycle, dependency resolver) **+** a `Packager`/`Installer` (ZIP + validation +
  filesystem) **+** asset rendering → **Asset Manager**.

`Database` has a **mild** violation (`setting()`/`setSetting()` is a Settings
concern) — extract to `Slate\Services\Settings` in Phase 3. `PublicRouter` is clean.

**A3 rule:** no splits during A3. Each class migrates as a single aliased class; the
SRP notes make the later split deliberate.

---

## Template

Each review has five sections: **Current implementation** · **Coupling analysis** ·
**Migration strategy** · **Verification plan** · **Future evolution**, plus an SRP
note. Reviews are grounded in the actual source (verified line references).
