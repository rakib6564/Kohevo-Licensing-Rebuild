# Kohevo Brand Persistence System — Phase 4 (System/Error Surfaces)

**Status:** Implemented
**Date:** 2026-09-21

## What changed

`PlatformSignature::render(MODE_SIGNATURE)` is now rendered once, at the
bottom of the card (after the existing `.biz` tenant-name div), inside the
**single shared renderer**, `includes/error_page.php`'s `slate_render_error()`.
Because every generic error/system surface in this app already funnels
through that one function, this one change covers all of them — no caller
(`404.php`, `403.php`, `500.php`, `PublicRouter::renderNotFound()`,
`plugins/forms/public/router.php`'s 404, and `slate_maintenance_gate()`'s 503)
needed its own edit, and none can duplicate the signature.

The signature renders **unconditionally**, independent of `$biz`/tenant
branding — proven by a unit test that renders the page with the `Database`
class entirely absent (a true DB-outage simulation, not a mock) and confirms
the signature still appears while the tenant `.biz` name correctly does not.

## The one real limitation found, and how it was resolved

`500.php` deliberately uses a minimal, plugin-free bootstrap (`includes/helpers.php`
+ `includes/Database.php` only — not `config.php`) specifically so a 500
caused by `PluginLoader::boot()` can't re-trigger itself. That bootstrap never
registered the `Slate\` PSR-4 autoloader, so `PlatformSignature`/`PlatformIdentity`
were genuinely unloadable on that one path — confirmed directly:
`class_exists('\Slate\Services\Content\PlatformSignature')` was `false` under
a reproduction of the old bootstrap, `true` after adding one line.

**Fix:** added `require_once SLATE_ROOT . '/src/autoload.php';` to `500.php`'s
existing try block. That file's own docblock describes it as additive/side-effect-free
class-loading registration only (no plugin code, no DB, idempotent) — it does
not reintroduce config.php or PluginLoader::boot(), so the risk `500.php`'s
minimal bootstrap exists to avoid is unchanged. This was the smallest fix that
closes the gap without touching `PlatformIdentity`/`PlatformSignature` at all.

`includes/error_page.php`'s own new code is additionally guarded with
`class_exists('\Slate\Services\Content\PlatformSignature')` (matching the
file's own pre-existing `class_exists('Database')` idiom just above it) —
belt-and-suspenders: even if some future caller reintroduces a bootstrap gap,
the error page degrades by silently omitting the signature rather than
fataling.

## Light/dark

`includes/error_page.php`'s card is a fixed dark, frosted-glass design (never
theme-dependent, unlike the Phase 2 admin sidebar) — the same problem Phase 2
solved for the tenant/dark-sidebar case. Reused the identical solution: a
small `.platform-signature img` CSS rule puts a light tile behind the mark
(which is otherwise dark ink), rather than extending `PlatformSignature`'s
API with a new mode or a dark-mark accessor. No change to `PlatformIdentity`/
`PlatformSignature`.

## System surfaces — final status

| Surface | Status |
|---|---|
| 404 | Integrated (via `slate_render_error()`) |
| 403 | Integrated (via `slate_render_error()`) |
| 500 | Integrated (via `slate_render_error()`, plus the `500.php` autoloader fix) |
| 503 / maintenance | Integrated (`slate_maintenance_gate()` calls the same `slate_render_error()`) |
| Generic system error | Integrated (any future caller of `slate_render_error()` inherits it automatically) |
| Generic empty state | **Not present** — the only reusable empty-state helper, `slate_portal_empty()`, is dead code (its one caller is in `archive/`); every active empty state is markup duplicated per admin page. No canonical generic renderer exists to integrate into. Left untouched. |
| Deployment/diagnostics | **Out of scope.** `admin/repair-settings.php` uses the standard admin shell and so already inherits the Phase 2 signature — nothing to add. `admin/diag.php` is deliberately shell-free by its own docblock ("no admin shell so nothing can mangle the output"), env-gated (`SLATE_DIAG_ENABLED`), platform-admin-only, and explicitly a temporary incident tool meant to be deleted after use — not a generic surface, and adding branding there would work against its own stated design intent. |

## Non-goals for this phase

No authentication/authorization/session/CSRF/RBAC change. No new error
architecture, no second branding helper, no Presentation-layer migration. No
database change, no licensing/white-label work, no Slate→Kohevo literal
cleanup. Phase 2 (shell) and Phase 3 (login) integrations verified unchanged.
