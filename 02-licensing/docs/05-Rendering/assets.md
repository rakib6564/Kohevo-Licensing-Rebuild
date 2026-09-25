# 05 — Asset Manager

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

How CSS, JS, and fonts are registered, deduped, fingerprinted, and emitted — **at
runtime, with no build step**. The Asset Manager is what lets Slate ship
themeable, cache-friendly front-ends on shared hosting without webpack.

> **Problem being solved.** Today ([AUDIT-BRIEFING](../AUDIT-BRIEFING.md)) each
> subsystem emits its own `<head>` and pulls its own fonts; cache-busting is
> mtime-based. The Asset Manager centralizes emission and moves to content-hash
> fingerprints so Cloudflare and browsers cache aggressively and correctly.

---

## 1. No build step (ADR-0003)

Assets are composed at request time and cached, never compiled at deploy. A
contributor edits a `.css`/`.js` file and refreshes — nothing to rebuild. Any
optional concatenation/minification happens at runtime and is cached like any
other artifact.

---

## 2. Registration

Modules and Themes register assets by **handle** with dependencies; nothing is
emitted twice.

```php
interface Assets {
    public function register(string $handle, string $path, array $deps = [], string $area = 'public'): void;
    public function enqueue(string $handle): void;      // request this handle for this response
    public function head(): string;                     // resolved <link>/<style> bundle
    public function foot(): string;                     // resolved <script> bundle
}
```

- **Dedup + dependency order.** A handle enqueued by three blocks emits once, in
  dependency order.
- **Area-aware.** `admin`, `public`, `email` bundles are separate.
- **Declared in the manifest** ([06-SDK/manifest.md](../06-SDK/manifest.md)) for
  the static case; `enqueue()` for the conditional case.

---

## 3. Fingerprinting & caching

- **Content-hash URLs** (`members.css?v=<sha>`), not mtime. The hash changes only
  when content changes, so Cloudflare's 7-day edge cache and the browser cache are
  both safe and long-lived. (Formalizes the current `?v=` scheme.)
- **Immutable caching headers** on fingerprinted assets
  ([13-Operations/performance-and-caching.md](../13-Operations/performance-and-caching.md)).
- **Base-path aware** — asset URLs respect the `/slate/` install sub-path.

---

## 4. Critical CSS / tokens inlined

The resolved [Design Token](../04-Design-System/design-tokens.md) set (the tenant
Theme's `:root` values) is **inlined** in `<head>` — it's tiny and must not
flash. Larger component/module CSS loads as fingerprinted external files so the
edge caches them. This keeps first paint correct and themed with zero external
requests for the token layer.

---

## 5. Fonts

- **System-font pairings by default** (Theme Engine) — zero external fetches,
  instant text, privacy-friendly.
- Optional web fonts are registered as assets (self-hosted, fingerprinted) so
  they inherit the same caching and CSP posture — never hot-linked from a CDN by
  default.

---

## 6. Where it sits in the pipeline

The Template's head-assembly stage ([rendering-pipeline.md](rendering-pipeline.md))
asks the Asset Manager for `head()`/`foot()` bundles for the current area. Full-
and fragment-page caches store the resolved output, so steady-state responses pay
nothing to recompute bundles.

---

## Related

- [rendering-pipeline.md](rendering-pipeline.md) · [theme-and-template-engine.md](theme-and-template-engine.md) · [04-Design-System/design-tokens.md](../04-Design-System/design-tokens.md)
- [13-Operations/performance-and-caching.md](../13-Operations/performance-and-caching.md) · [ADR-0003](../14-ADR/)
