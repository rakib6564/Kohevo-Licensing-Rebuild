# 13 — Performance & Caching

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

Where caching happens, how it's invalidated, and the performance budgets the
platform holds itself to. Caching is layered and **tag-invalidated on writes**, so
it's aggressive without going stale.

> **Problem being solved.** Today ([AUDIT-BRIEFING](../AUDIT-BRIEFING.md)) every
> request runs a `plugins` query + a `plugin.json` read + `version_compare` +
> per-plugin settings reads, with **no boot cache**. Steady-state cost grows with
> module count. The boot cache alone is the biggest single win.

---

## 1. The caching tiers

| Tier | Caches | Key | Invalidated by |
|---|---|---|---|
| **Boot cache** | compiled module manifest: routes, nav, permissions, subscriptions | platform + modules version | activate/deactivate/upgrade |
| **Config/settings** | per-tenant settings snapshot | tenant | settings write |
| **Full-page** | rendered public HTML | tenant + route + `theme_v` + `content_v` | content/theme save (tag) |
| **Fragment** | per-Section/Block output | block instance + `content_v` | block edit (tag) |
| **Query** | hot read paths | query signature + tenant | entity write (tag) |
| **Edge (Cloudflare)** | cacheable HTML + assets | URL + content-hash `?v=` | correct `Cache-Control`/`ETag` |
| **Asset** | fingerprinted CSS/JS/fonts | content hash | new hash on change |

All keys are **tenant-prefixed** so tenants never share cache entries
([01-Architecture/multi-tenancy.md](../01-Architecture/multi-tenancy.md)).

## 2. The boot cache (highest-value fix)

The module manifest — which modules are active, their routes, nav, permissions,
event subscriptions — is compiled once and cached (file/APCu default, Redis
optional). A request reads the compiled artifact instead of re-scanning
`plugin.json` files and re-querying settings. Invalidated on any module
lifecycle change. This flattens the per-request cost curve as module count grows.

## 3. Tag-based invalidation

Cache entries carry **tags** (`content:page:42`, `theme:tenant:3`). A write emits
an invalidation for the tag, dropping exactly the affected page/fragment/query
entries — not the whole cache. This is what makes full-page caching safe for a
CMS: publishing page 42 invalidates only page 42's entries.

## 4. The render/cache interaction

The [rendering pipeline](../05-Rendering/rendering-pipeline.md) probes the
full-page cache first (public GETs); a hit short-circuits before tenant/auth work.
A miss renders, then stores the result tagged with the content + theme versions
(SEO meta and asset bundles included), so the next hit is free.

## 5. Performance budgets

| Metric | Target (default posture) |
|---|---|
| Boot overhead (cached) | negligible; no per-request file scans |
| Cached public page (edge/full-page hit) | served without app work |
| Uncached public page | dominated by content query + render, not framework overhead |
| Admin page | thin controller → service → scoped query |

Slow work (email, webhooks, sitemap builds, image derivatives) runs on the
[queue](shared-hosting-compatibility.md), never on the request path — synchronous
email sends (today's pattern) move to queued `NotificationChannel`s.

## 6. Scaling the drivers

Under load, swap the cache/queue drivers to Redis and add a worker
([shared-hosting-compatibility.md](shared-hosting-compatibility.md)) — the cache
*keys and tags are identical*, so nothing in the modules changes.

---

## Related

- [README.md](README.md) · [shared-hosting-compatibility.md](shared-hosting-compatibility.md) · [logging-and-auditing.md](logging-and-auditing.md)
- [05-Rendering/rendering-pipeline.md](../05-Rendering/rendering-pipeline.md) · [05-Rendering/assets.md](../05-Rendering/assets.md)
