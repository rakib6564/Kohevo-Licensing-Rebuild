# Platform brand assets — Kohevo

Platform-owned, versioned, immutable-from-the-application identity assets.
Resolved exclusively through `Slate\Services\Content\PlatformIdentity`
(`markUrl()`, `wordmarkUrl()`, `wordmarkDarkUrl()`, `faviconUrl()`) — never
referenced by a hardcoded path anywhere else. See
`docs/09-Roadmap/phase-kohevo-identity-p1.md`.

This directory is distinct from `uploads/branding/`, which holds
tenant-uploaded logos/favicons/login images and is gitignored, runtime, and
tenant-writable. Nothing under `assets/` is reachable by a tenant upload —
`Uploads::handle()` only ever writes under `uploads/`.

## Status: official artwork (Phase 1A)

Every SVG/PNG here is the **official Kohevo monogram package**, "Monogramme K
pour Kohevo", copied byte-for-byte from the supplied ZIP — paths, geometry,
colors, and embedded metadata (each SVG carries a C2PA content-credentials
manifest from the export tool) are untouched. `LISEZMOI.txt` is the brand
package's own usage guidance, copied unmodified and reproduced/summarized
below — it is the authoritative source for what each variant is for, not the
filenames alone.

Phase 1 shipped this directory with three placeholder SVGs
(`kohevo-mark.svg`, `kohevo-wordmark.svg`, `kohevo-wordmark-dark.svg`) that
were never approved artwork. Phase 1A (this state) removed them entirely —
there is no ambiguity left between placeholder and official files, only the
official package below.

## Files (as supplied, exact original filenames)

| File | LISEZMOI's own description | Usage guidance |
|---|---|---|
| `kohevo-compact-{noir,blanc}.{svg,png}` | Variante une bande | Favicon, app icon, engraving — from 16px |
| `kohevo-signe-3-bandes-{noir,blanc}.{svg,png}` | Signe complet, trois bandes | Full sign — from 32px |
| `kohevo-lockup-horizontal-{noir,blanc}.{svg,png}` | Signe + "ohevo" a droite, alignes sur la ligne de base | Horizontal wordmark lockup, one baseline |
| `kohevo-lockup-vertical-{noir,blanc}.{svg,png}` | Signe + KOHEVO sous le signe | Vertical/stacked wordmark lockup |

`noir` = pure black fill (`#000000`), for light backgrounds. `blanc` = pure
white fill (`#ffffff`), for fixed-dark backgrounds. Transparent background,
no gradient/outline on any variant. PNGs are 1200px tall (2400px wide for the
horizontal lockup) per the package's own spec — confirmed by inspection.

**Trademark-filing note from LISEZMOI**, reproduced because it matters if
these are ever reused outside this app: the `signe`/`compact` shapes are pure
outline (single closed contour per band, filing-ready as supplied); the two
lockups contain **live text in Archivo Medium** — LISEZMOI says to convert
text-to-outlines before any trademark filing or print use. Not relevant to
web rendering (this app only ever displays these as SVG/PNG images), but
recorded here since it's the source document's own instruction.

## PlatformIdentity mapping

| `PlatformIdentity` method | Resolves to |
|---|---|
| `markUrl()` | `kohevo-compact-noir.svg` |
| `wordmarkUrl()` | `kohevo-lockup-horizontal-noir.svg` |
| `wordmarkDarkUrl()` | `kohevo-lockup-horizontal-blanc.svg` |
| `faviconUrl()` | `kohevo-compact-noir.svg` (same file as `markUrl()` — LISEZMOI explicitly names this variant for favicon use) |

`kohevo-signe-3-bandes-*` (the larger, from-32px full sign) and
`kohevo-lockup-vertical-*` (the stacked lockup) are present on disk but have
no `PlatformIdentity` accessor yet — nothing in the current architecture
needs them; add a method for one only when a real consumer does.

`assets/img/kohevo-favicon.ico` (pre-existing, outside this directory) is
unchanged — not deleted, not moved. It remains what the separate,
pre-existing `slate_default_favicon_url()` helper resolves to; nothing live
resolves a favicon through `PlatformIdentity` yet, so this directory's
`faviconUrl()` mapping is a Phase-1A-safe choice with no production effect.
