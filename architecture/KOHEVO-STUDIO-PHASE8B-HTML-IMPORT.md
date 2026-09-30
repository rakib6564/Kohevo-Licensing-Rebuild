# Kohevo Studio — Phase 8B: Constrained HTML/CSS Structural Import

Status: implemented (Phase 8B only). 8C (JSX/React/Tailwind/v0), 8D (ZIP) and
8E (Figma) are **not** implemented; there is no MCP import tool, no raw-HTML
block, no stored CSS and no remote fetching. No schema change, no new
permission key, no new dependency.

## 1. Where it sits — one import pipeline

```
HTTP  admin/api.php → StudioAuthoringApi  import_html (POST, dry_run: true|false)
        same transport as import_package: session actor, CSRF + Sec-Fetch-Site, JSON,
        1.5 MiB body, package rate-limit bucket (30/min shared), strict field allowlist
        fields: html, css, title, slug, page_type (page|landing), dry_run, mode,
                target_page_id, expected_revision_id, media_map — nothing else, no tenant
  → StudioApplicationService::importHtml()
        authorize(edit) + importKind() (AI origin refused)      ← BEFORE any parsing
        read-only resolved theme (ThemeResolver, default group)
        → Package\Html\HtmlImportConverter::convert()  (PURE: no DB, network, fs, audit)
              capability → raw guards → parse → security walk → CSS subset
              → computed evidence → import IR → canonical document
              → SYNTHESIZED one-page Kohevo package (the Phase 8A format)
        → StudioPackageService::plan(package, …, report)   ← the Phase 8A planner, unchanged
        → [commit] commitImportPlan(): the ONE owned transaction, then invalidation + audit
```

`commitImportPlan()` is the commit half extracted from `importPackage()`
(behaviour-preserving: `kohevo_json` reports and audit events are unchanged).
Everything after conversion — media resolution, id re-minting, validation,
route collision / reserved route, replace target + expected revision (409),
draft commit with revision kind `import`, audit after commit — is the 8A code.
Nothing publishes; `published_revision_id` is never touched.

## 2. Converter stages (`Package/Html/`)

| Stage | Class | Rules |
|---|---|---|
| capability | `HtmlImportConverter` | `dom` + `libxml` + `DOMDocument` + `LIBXML_NONET`, else `html_import_unavailable` (fail closed, nothing else runs) |
| raw guards | `HtmlSourceReader::guard` | HTML ≤ 512 KiB, CSS ≤ 128 KiB (field + `<style>` text), ≤ 12,000 `<`, valid UTF-8, no NUL / C0 except `\t\n\r`, no DOCTYPE internal subset |
| parse | `HtmlSourceReader::read` | non-ASCII → numeric entities (libxml assumes Latin-1 without a charset), `loadHTML($html, LIBXML_NONET)`; never NOENT / PARSEHUGE / HTML_NOIMPLIED; libxml "Excessive depth" is fatal |
| walk | `HtmlSourceReader` | explicit stack; ≤ 5,000 elements, ≤ 10,000 nodes, depth ≤ 64, ≤ 400,000 text chars; ≤ 32 attributes (else ignored), values ≤ 2,048 B, ≤ 32 classes/element, ≤ 1,000 distinct |
| CSS | `HtmlCssParser` | bounded tokenizer; ≤ 2,000 rules, ≤ 5,000 declarations, ≤ 64/rule, selector ≤ 256, value ≤ 256, inline ≤ 32 |
| values | `HtmlCssValues` | exact token match; closed-scale quantization |
| structure | `HtmlStructureMapper` | IR + heuristics + canonical blocks |
| IR bounds | `HtmlImportIr` | ≤ 2,000 nodes, depth ≤ 8, strings ≤ 50,000, ≤ 250 children, plain data only |

Limits are fatal (`source_too_large`, `source_limit_exceeded`,
`output_limit_exceeded` for > 50 sections / > 250 blocks) — output is never
silently truncated.

### libxml HTML4-parser differentials corrected (found while implementing)
- HTML5 void elements it does not know (`source`, `track`, `embed`, `param`,
  `keygen`, `wbr`) are parsed as containers that swallow their following
  siblings: the element is dropped/reported and the swallowed siblings are
  walked as siblings (through the same filters).
- HTML5 elements after `<title>` stay inside `<head>`: any non-head element
  or text there is treated as body content.
Output is never the parser's markup (rich text is rebuilt), so differentials
affect fidelity only, not safety.

## 3. Security boundary

- **Stripped with the whole subtree, reported `security_stripped`:** script,
  noscript, style (text → CSS budget first), iframe, frame, frameset, object,
  embed, applet, form + every control (input, textarea, select, option,
  optgroup, button, label, fieldset, legend, output, datalist, keygen),
  canvas, video, audio, source, track, svg, math, template, portal, param;
  `on*`, `srcdoc`, `formaction`, `action`, `xlink:*` attributes.
- **Unsupported, reported `unsupported_element`:** nav (never chrome), the
  table family, hr, details/summary, dialog, map/area, meter, progress,
  marquee, menu, slot.
- **Read as evidence only, never persisted:** href, target, rel, src, alt,
  class, id, role, style. data-*, srcset, aria-* are not read.
- **URLs:** every href/src goes through `FieldSchema::isSafeUrl()` (unchanged).
  Unsafe links downgrade to text, unsafe images are omitted (`unsafe_url`).
- **Rich text is rebuilt**, never passed through: bare allowlisted tags
  (p br strong em b i u s ul ol li blockquote code pre h1–h6) and
  `<a href target rel>` only; each unit is pre-checked with
  `FieldSchema::validateRichText()` and dropped **alone** when refused
  (`unsafe_text_dropped` — the validator's known false positives on prose
  like "Insert into…", "JavaScript:" are not fixed here). The canonical
  validator remains the final authority; the renderer re-sanitizes on output.
- `header` / `footer` become ordinary sections (`chrome_not_imported`); no
  `header_partial` / `footer_partial` / system page is ever created
  (`page_type` is page|landing only).

## 4. CSS

Selectors: type, `.class`, `#id`, compounds, `:root` (custom properties).
Combinators, attribute selectors, pseudo-classes/elements and `*` are
dropped (`unsupported_css`). Cascade: id > class > type, then order; inline
wins; `!important` ignored; text-align / color / font-family inherit.
`var(--x)` resolves one level from literal `:root` properties.

Dropped and reported: @import, @font-face, @keyframes, @supports, @layer,
@container, @page, @namespace, @charset, any other at-rule except exact
`@media`; `url(`, `expression(`, `javascript:` values; position (except
static), z-index, transform*, animation*, transition*, filter,
backdrop-filter, -moz-binding, behavior; any backslash escape.

| CSS | Canonical | Strategy |
|---|---|---|
| text-align | `style.align` | exact |
| padding(-top/-bottom) on a section | `layout.padding_y.base` | nearest bucket, ties → smaller (`style_quantized`) |
| gap / row-gap / column-gap / grid-gap | `layout.gap`, `core.container.gap` | nearest bucket |
| max-width (section or its single wrapper) | `layout.width` | nearest bucket; none/100% → full |
| display flex/grid, flex-direction, grid-template-columns | `core.container` direction; `core.feature_list.columns` | exact |
| background-color, color, border-radius, box-shadow, font-family, uniform padding | `surface/text/radius/shadow/font/spacing_token` | **exact** match against the tenant's resolved theme, registry refs only |
| display:none | content not imported (`content_dropped_hidden`) | — |
| exact-breakpoint `@media` display | `visibility.devices` | exact buckets (640/768/1024) only |

No colour quantization, no custom tokens (tokens have no draft state), no
CSS text stored. Unmatched values: `css_value_unmapped` (once per value).
Sections always get `columns: {base: 1}`: the canonical default
(`{base:1, md:12}`) would put imported blocks side by side, and schema 1.0
has no per-block spans — grid structure maps to `core.container` instead.

## 5. Structure mapping (current registry shapes)

`h1–h6` → `core.heading {text, level}` · flow text → `core.rich_text {content}`
(adjacent units merged ≤ 50,000) · button-like links (class btn/button/cta,
role=button, lone link in a wrapper) → `core.button {link{label,href,target}, variant}`
· `/uploads/` images → `core.image {media{media_id, alt}, caption}` ·
hero (optional eyebrow, exactly one h1, optional paragraph, ≤ 1 button,
≤ 1 image, nothing else) → `core.hero` · 2–12 uniform cards (one heading,
≤ 1 paragraph, ≤ 1 link, no image) → `core.feature_list {columns, items}` ·
flex/grid wrappers → `core.container {gap, direction}` (depth ≤ 4, else
transparent) · section/article/header/footer/main and body-level blocks →
sections. Module blocks are never produced.

## 6. Media

No fetch, no binary, no media row, no URL stored. `/uploads/…` paths
(jpg, jpeg, png, gif, webp, avif, svg) become package media descriptors and
resolve through the 8A order: explicit `media_map` (must be an image of THIS
tenant — a foreign id is an error) → tenant-local row with the same path →
otherwise the image is omitted (`unresolved_media`). External URLs:
`unresolved_media` (detail `external_url`, host only). data: URIs:
`security_stripped` (never decoded). Inline SVG: stripped.

## 7. Report

The 8A `StudioImportReport` with `source_kind: "html_css"`, plus
`source_hash` and `conversion` counts (a `kohevo_json` report is byte-for-byte
unchanged). Converter findings are ordinary issues with source locations
(`html:L12 body>section[2]>p[1]`, `css:rule[4]`), capped at 100 per code
(`issues_truncated`). Deterministic for the same source and tenant theme.

## 8. Builder UI

Import tab → source switch "Kohevo package (.json)" / "HTML/CSS" → HTML file,
optional CSS file, title and slug (create mode), the existing mode selector.
Same Analyse → "Import into draft" gate (`canImport` on the analysis key of
exactly the current inputs), same `ReportView` (plus the conversion counts),
same replace path through `SyncEngine.command()` (409 keeps local edits).
New strings: English in `messages.mjs`, French via `admin/builder.php`
(`boot.messages`, `__('studio_ui_…')`) and `lang/fr.php`.

## 9. Runtime / CI

`dom` + `libxml` are required only for this import (documented as optional
in CLIENT-SETUP, GO-LIVE-CHECKLIST, SHARED-HOSTING, README). CI now installs
and asserts them, and runs the Studio unit + integration suites
(`tests/run-studio-builder.php unit|integration`) and the builder UI tests.
