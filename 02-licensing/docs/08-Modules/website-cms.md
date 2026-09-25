# 08 — Website / CMS Module

**Status:** Draft · **Applies to:** Slate v2.x

## Purpose

Build and manage websites — pages, posts, custom post types, taxonomies, and
navigation menus — on the platform's rendering stack. This module is the
promotion and cleanup of today's `content-builder`.

## Bounded context

**Content** ([02-Domain](../02-Domain/)).

## Consumes

| Service / capability | For |
|---|---|
| Rendering + Block Registry | assembling Pages from Sections/Blocks ([05-Rendering](../05-Rendering/)) |
| Theme + Template Engine | skinning and document frame |
| SEO Manager | per-entity meta + sitemap contribution |
| Media Manager | images in content |
| Identity | authored-by / audience (optional) |

## Provides

- Generic **content blocks** (heading, rich text, image, columns, hero, CTA,
  gallery, post-list…) registered via `blocks.register` — **generic only**;
  vertical blocks live in their own modules (fixes `rx-*` in core).
- Content types + taxonomies other modules can render.
- `page.published` / `post.published` events.

## Owns

- `websitecms_posts`, `_post_meta`, `_terms`, `_term_map`, `_menus`,
  `_menu_items` (slug-prefixed).
- **Does NOT own:** blocks (core registry), themes (Theme Engine), media (Media
  Manager), or people. SEO values are post-meta but rendered by the SEO Manager.

## Routes & admin

- Public: `/p/<slug>` (pages) and `/<type>/<slug>` (custom types), with draft
  preview.
- Admin: content list/editor (the [visual Page Builder](../05-Rendering/page-builder.md)
  in v2), menus, taxonomies, per-page SEO.

## Integration events

- **Emits:** `page.published`, `post.published` → SEO reindex, cache invalidation
  ([13-Operations/performance-and-caching.md](../13-Operations/performance-and-caching.md)),
  search indexing.
- **Contributes:** `sitemap.collect` (its public URLs), `nav.items` (admin).

## Why a module, not core

Rendering primitives (tokens, components, blocks, sections, templates) are core so
*everything* can render; the CMS is the *authoring product* on top of them — a
site that doesn't need a CMS doesn't activate it, yet still renders module pages
through the same pipeline.

---

## Related

- [05-Rendering](../05-Rendering/) · [05-Rendering/page-builder.md](../05-Rendering/page-builder.md) · [05-Rendering/seo-rendering.md](../05-Rendering/seo-rendering.md) · [README.md](README.md)
