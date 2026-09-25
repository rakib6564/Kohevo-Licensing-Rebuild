# ADR-0003 — Server-rendered + progressive enhancement; no build step

**Status:** Accepted   **Date:** 2026-07-27

## Context

Slate produces public websites, storefronts, and admin UIs that must be
SEO-complete, fast on modest hardware, and editable by contributors without a
toolchain. A modern default would be an SPA (React/Vue) with a build pipeline.

## Decision

**Server-render HTML**; use JavaScript as **progressive enhancement**. Ship **no
build step** — assets are composed at runtime and cached
([05-Rendering/assets.md](../05-Rendering/assets.md)). Content and navigation work
without JS; JS adds affordances (the visual Page Builder, live previews).

## Alternatives considered

- **SPA + API-first frontend.** Rejected: a build/deploy pipeline breaks
  upload-and-run; hydration and client routing add weight and complexity; SSR-for-
  SEO would have to be re-added anyway; excludes contributors without Node.
- **Static-site generation.** Rejected: incompatible with per-tenant dynamic
  content, portals, carts, and bookings.

## Consequences

- **Positive:** SEO is free (content is in the initial HTML — [05-Rendering/seo-rendering.md](../05-Rendering/seo-rendering.md));
  low operational complexity; fast first paint with inlined token CSS; editable by
  anyone with an editor; strong accessibility baseline.
- **Negative / accepted trade-offs:** rich client interactions require deliberate
  progressive-enhancement work rather than reaching for a component framework; the
  Page Builder's editor is more effort to build on vanilla JS; no automatic
  client-side type safety. We accept these to keep the deploy-anywhere promise.

## Related

- [ADR-0001](0001-flat-php-over-framework.md) · [ADR-0008](0008-one-design-token-vocabulary.md)
- [05-Rendering](../05-Rendering/) · [13-Operations](../13-Operations/)
