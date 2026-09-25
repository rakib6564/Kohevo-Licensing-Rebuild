# Design Review — `PublicRouter`

**Source:** `includes/PublicRouter.php` (113 lines) · **Callers:** 3 files · **No code.**

The smallest surface and lowest risk of the four — a focused public-URL dispatcher.
Recommended to migrate **first** as a warm-up for the `Slate\Kernel\Http` namespace.

---

## 1. Current implementation

**Responsibilities** — one: map a clean public path to a plugin handler file
(longest-prefix-match), gate by HTTP method, expose the remainder to the handler,
render a branded 404 when unmatched.

**Public API** (static)
| Method | Purpose |
|---|---|
| `dispatch(path): void` | match the path against `public_routes`, `require` the handler (or 404) |
| `knownPrefixes(): array` | list registered prefixes (for admin "your public URLs" surfaces) |

`renderNotFound()` is private.

**Internal dependencies**
- `Hook::applyFilters('public_routes', [])` — the route table (lines 49, 103).
- `slate_log()` (missing-handler warning), `slate_render_error()` (via
  `require_once includes/error_page.php`).
- Superglobals `$_GET` (writes `_route_prefix` / `_route_path`), `$_SERVER`
  (`REQUEST_METHOD`).
- Global function/const fallbacks only otherwise.

**External callers** — 3 files: `public.php` (delegates the fall-through path),
`route.php` (the rewrite trampoline), and one admin surface using `knownPrefixes()`.
Total `PublicRouter::` calls: 3, all `dispatch`.

**Bootstrap order** — not touched at boot. `public.php` requires `config.php` (which
loads the class) then calls `PublicRouter::dispatch()` for paths that don't resolve
to a real file. Opt-in: anything on disk is served by Apache directly.

**Lifecycle** — stateless; pure per-request dispatch. No static state.

---

## 2. Coupling analysis

- **Who depends on it:** only the public entry points (`public.php`/`route.php`) and
  one admin list view.
- **What it depends on:** `Hook` (already migrated) and the `error_page.php` helper
  (a `require`, not a class).
- **Circular dependency risk:** **none.** Handlers it `require`s are plugin files
  that may call back into `Hook`/`Database`, but that is normal request flow, not a
  load cycle.
- **Hidden assumptions:**
  - Handler paths are required to be **absolute file paths**; the `..`-stripping in
    `dispatch()` is defense-in-depth on the *URL*, not the handler.
  - Longest-prefix-match semantics (a plugin registering `forms/admin` can intercept
    before `forms`) must be preserved.
  - It writes `$_GET['_route_prefix']`/`['_route_path']` as the handler contract —
    plugin routers read these.
- **Global state:** none (no static properties).

---

## 3. Migration strategy

- **Namespace / location:** `Slate\Kernel\Http\PublicRouter` → `src/Kernel/Http/PublicRouter.php`.
- **Qualification:** `Hook::` → `\Hook::` (2 sites). `\Throwable` n/a. All other
  symbols are global functions/constants (fallback) or superglobals. The
  `require_once SLATE_ROOT . '/includes/error_page.php'` stays as-is (path require,
  not a class).
- **Alias strategy:** `class_alias(\Slate\Kernel\Http\PublicRouter::class, 'PublicRouter');`.
  Three callers keep using the global name.
- **Bootstrap considerations:** none special — it is not loaded at boot, only on the
  public fall-through path. Standard forwarder + alias pattern.
- **Backward compatibility:** total; 3 callers unaffected; handler contract
  (`$_GET` keys, longest-prefix-match) unchanged.
- **Risk assessment:** **LOWEST of the four.** Tiny surface, 3 callers, one
  cross-class symbol.
- **Rollback strategy:** `git revert` the single commit; additive change, no state.

---

## 4. Verification plan

- **Existing smoke coverage:** none directly (smoke is CLI; the router runs on the
  public fall-through path). `class_exists('PublicRouter')` after alias is the
  minimum.
- **Additional checks required:**
  - Verify `class_exists('PublicRouter')` resolves to `Slate\Kernel\Http\PublicRouter`.
  - Verify `knownPrefixes()` returns the active plugins' registered prefixes
    (exercises `\Hook::applyFilters('public_routes')` cross-namespace).
- **Runtime verification:** hit a real public route end-to-end — `/book` (booking)
  and `/forms/<slug>` — and confirm the handler runs and `_route_prefix`/`_route_path`
  are set. Hit an unmatched path and confirm the **branded 404** renders (not a bare
  message). Confirm a `405` on a disallowed method for a method-gated route.
- **Edge cases:** empty path, path with `..` segments (must be stripped), a
  registered prefix whose handler file is missing (logs + 404), longest-prefix
  precedence.
- **Plugin compatibility:** booking, forms, content-builder, shop all register
  `public_routes` — exercise at least booking + forms live.

---

## 5. Future evolution (Architecture v1.0)

- **Service Container / Kernel:** becomes part of the unified **Router** inside the
  HTTP kernel behind the front controller ([request-lifecycle](../../01-Architecture/request-lifecycle.md)),
  rather than a standalone opt-in dispatcher invoked by `public.php`.
- **Dependency Injection:** the router receives the route table from the **boot
  cache** (declarative `routes` from module manifests) instead of calling the
  `public_routes` filter every request.
- **Capability Contracts:** handler resolution moves from "require a file path" to
  dispatching a module `Controller` resolved from the container.
- **Rendering pipeline:** the 404 path routes through the one rendering pipeline /
  error handler rather than a direct `require` of `error_page.php`.

### SRP note
**Clean — no split.** `PublicRouter` does one thing (public dispatch). It simply
*moves* into the HTTP kernel over time; no responsibility needs extracting.

---

## Recommendation

Migrate **first**. It is the safest possible way to stand up and validate the new
`Slate\Kernel\Http` namespace and the alias mechanics on a live public route before
touching `Database`/`Auth`/`PluginLoader`.
