# Kohevo Studio Builder: design tokens and global settings audit (r11, Part 2)

Read-only, source-driven audit of tree `wt-final/01-client` (HEAD `71d4e83`). Paths are relative to that tree. The full row-level inventory is in `studio-design-tokens.csv` (60 rows).

## 0. Summary

**Inventory**
- 26 editable Studio design tokens.
- 2 CSS variables that are referenced but never defined.
- 3 runtime animation variables.
- 2 page-level settings.
- 5 section-layout options.
- 6 hard-coded scale/width preset families.
- 1 breakpoint set.
- 9 branding settings: 5 written by the admin Branding form, plus 4 read by Studio that nothing writes.
- 5 admin-chrome derived variable families.
- 1 Studio-admin variable set.
- Custom CSS.
- The editor skin: 64 `--sbx-*` variables, summarised in one row.
- The mobile Theme sheet's 6 fake tokens.

**Findings:** High 3, Medium 7, Low 6, Info 4.

**Main conclusions**

1. **The token pipeline itself is sound.** It is a closed list (`ThemeResolver::DEFAULT_TOKENS`) and validated per category on both write and read.
   - Values are emitted as one `:root{--sb-*}` block, built from sanitised values only.
   - The theme is part of the compile fingerprint, so a token save invalidates published artifacts.
2. **The "site branding applies first" layer is mostly dead.** Studio reads 5 branding keys. Only `brand_accent_color` has a writer. The admin Branding form's font and radius settings feed the admin chrome only, never Studio.
3. **Hard-coded scales do not follow the tokens.**
   - Radius, shadow, spacing and gap presets are fixed values.
   - So are `layout.section` and `layout.container` widths.
   - Only the document section width follows `layout.*`. Editing "Medium corner" or "Medium shadow" in Site settings does not change blocks that use the Medium preset.
4. **The mobile Theme sheet edits 6 token refs the server does not accept,** so every save fails. Its brand, logo and colour-mode fields are never saved.

---

## 1. Token pipeline (how a token travels)

| Stage | Where |
|---|---|
| Definition and defaults (closed list of 26) | `ThemeResolver::DEFAULT_TOKENS` ThemeResolver.php:40-67 |
| Allowed ref shape | `TOKEN_REF_PATTERN` CanonicalDocumentSchema.php:311 (`surface\|text\|space\|radius\|shadow\|font\|color\|border\|layout`) |
| Per-category value guard | ThemeResolver.php:155-179 (colour / length list / shadow / layout `px\|rem\|em` / font pattern) |
| Layers, later wins | defaults → tenant branding (BRANDING_TOKEN_MAP ThemeResolver.php:70-76) → stored group (ThemeResolver.php:87-113) |
| Editor read | `GET tokens` StudioAuthoringApi.php:70 → `StudioThemeService::layers` StudioThemeService.php:59-80 |
| Editor UI | `SiteSettingsForm` SiteSettings.jsx:54-192, groups siteSettings.mjs:7-14, labels messages.mjs:511-551. Hosted in docked Settings › Site and `ThemeDialog` (ThemeDialog.jsx:10-19) |
| Write | `POST save_tokens` StudioAuthoringApi.php:98 → `saveTokens` StudioThemeService.php:90-109. Accepts only DEFAULT_TOKENS refs (L140-147) and at most 64 per group. Needs permission `tokens` (manifest StudioApplicationService.php:1462) |
| Storage | `studiobuilder_tokens(tenant_id, token_group, tokens_json, compiled_css_vars)` install.sql:100-110. `compiled_css_vars` is written (StudioThemeService.php:99) but never emitted (ThemeResolver.php:19-20) |
| Group selection | page `settings.token_group` (default `default`; falls back to the default group, ThemeResolver.php:208-210) |
| Emission | `ResolvedTheme::rootCss` ResolvedTheme.php:65-72, appended in StudioCompiler.php:103. Name mapping `cssVarName` ResolvedTheme.php:60-63. A duplicate mapping in StyleSurface.php:356-361 is kept equal by a unit test |
| Consumption | base stylesheet `var(--sb-*)` (StudioStylesheet.php). Block/section token classes `sb-{bd\|bg\|fg\|font\|pad\|rad\|shd}--{ref}` (RenderCollector.php:25-33, 54-62; DocumentRenderer.php:53-61, 262, 407-409). Colour-token values in StyleSurface `tcolor` fields (StyleSurface.php:339-361). `layout.section.background_token` (SectionRenderer.php:66-72) |
| Cache invalidation | `'theme' => $theme->fingerprint()` in compile inputs (StudioCompiler.php:311) |
| Editor parity | the canvas is the same server render. Live prediction maps token classes in liveStyle.mjs:43-46. After a save the canvas reloads and the manifest refreshes (StudioShell.jsx:610-617) |
| Responsive | **none.** Tokens have no per-device values |

**Block-level token pickers:**
- `surface_token`, `text_token`, `border_token`: StyleControls.jsx:248-250, 386, 458, 514.
- `spacing_token`: SurfaceControls.jsx:300-303.
- All 7 token keys in the Style › Theme tokens section: BlockInspector.jsx:195-204; categories in fields.mjs:43-51.
- Section background token: SectionInspector.jsx:140-142.
- Picking a token removes the competing literal (tokenStyle.mjs:12-84).

## 2. The 26 Studio tokens

| Token | Default | CSS var | Used by the base stylesheet? | Reaches output |
|---|---|---|---|---|
| border.default | #e7e5e4 | --sb-border-default | yes, 17 rules (StudioStylesheet.php:55-57, 94, 100 …) | always |
| color.accent | #1f2937 | --sb-color-accent | yes, 14 rules (L51, 66, 69-70, 78 …) | always |
| font.body | system-ui | --sb-font-body | L46 | always |
| font.heading | system-ui | --sb-font-heading | L47 | always |
| layout.narrow / normal / wide | 42 / 64 / 80rem | --sb-layout-* | L420-424 `.sb-w-*` only | document-section width only |
| radius.sm | 4px | --sb-radius-sm | **no** | only if picked as `radius_token` |
| radius.md | 8px | --sb-radius-md | L66, 73, 93, 121, 139, 250, 261, 270 | always |
| radius.lg | 16px | --sb-radius-lg | L64 | always |
| radius.full | 9999px | --sb-radius-full | **no** | only if picked |
| shadow.sm | 0 1px 2px … | --sb-shadow-sm | **no** | only if picked |
| shadow.md | 0 4px 12px … | --sb-shadow-md | L251 (post-card hover) | yes, narrowly |
| space.sm / md / lg | .75 / 1.5 / 3rem | --sb-space-* | **no** | only via `spacing_token` |
| surface.page | #fff | --sb-surface-page | L46 | always |
| surface.primary | #fff | --sb-surface-primary | no, but it is the default section `background_token` (CanonicalDocumentSchema.php:382) | every default section |
| surface.secondary | #f5f5f4 | --sb-surface-secondary | L68, 95, 102, 250 | always |
| surface.accent | #1f2937 | --sb-surface-accent | L67, 262, 272 | always |
| surface.muted | #f5f5f4 | --sb-surface-muted | **no** | only if picked |
| surface.inverse | #111 | --sb-surface-inverse | **no** | only if picked or used by a preset (SectionPresetCatalog) |
| text.primary | #111 | --sb-text-primary | L46, 58, 68, 92, 147 … | always |
| text.muted | #57534e | --sb-text-muted | 14 rules | always |
| text.accent | #1f2937 | --sb-text-accent | **no** | only if picked |
| text.inverse | #fff | --sb-text-inverse | L67 (primary button label) | always |

Scope for all 26: tenant, per token group. Edit UI: SiteSettings.jsx TokenRow (L19-52) under the groups Colours / Fonts / Shape / Shadows / Spacing / Page widths.

## 3. Other global style options

| Option | Defined | Edited | Applied? |
|---|---|---|---|
| `settings.container_width` (page) | CanonicalDocumentSchema.php:58-59, 351 | PageInspector.jsx:177 | **No.** `.sb-main--*` has no core rule (StudioCompiler.php:99-102; StudioStylesheet.php:52) |
| `settings.token_group` | CanonicalDocumentSchema.php:353 | not in UI (package import/API) | yes (ThemeResolver.php:87-113) |
| `section.layout.width/gap/columns/padding_y/background_token` | CanonicalDocumentSchema.php:145-153, 379-388 | SectionInspector.jsx:107-142 | yes; `padding_y` and `background_token` are overridden by `section.style` |
| Spacing scale (padding) | StudioStylesheet.php:32 | props + section `padding_y` | yes, hard-coded 0-8rem |
| Gap scale | StudioStylesheet.php:33 | props + section gap | yes, hard-coded 0-3rem |
| Radius presets | CanonicalDocumentSchema.php:299; StudioStylesheet.php:241 | StyleControls.jsx:180, 489 | yes, hard-coded, **not** `radius.*` |
| Shadow presets | CanonicalDocumentSchema.php:298; StudioStylesheet.php:242 | StyleControls.jsx:177, 527 | yes, hard-coded, **not** `shadow.*` |
| `layout.section` widths | SectionRenderer.php:21; StudioStylesheet.php:166-169 | props | yes, hard 80/48rem |
| `layout.container` widths | LayoutContainerRenderer.php:19; StudioStylesheet.php:176-182 | props | yes, hard 75/48rem |
| Breakpoints | StudioStylesheet.php:23-30; StyleSurface.php:412 | n/a | yes |
| `studio_code_custom_css` | StudioCodePolicy.php:62 | admin/code-tracking.php:45, 122; CustomCss in Site settings (SiteSettings.jsx:188, admins only) | yes, every mode including the canvas (PageDocumentAssembler.php:47, 71) |
| `--sb-anim-*` | DocumentRenderer.php:505-530 | MotionInspector.jsx | yes (StudioStylesheet.php:273-281) |

## 4. Branding settings (admin/settings.php › Branding) vs Studio

| Setting | Writer | Read by Studio? | Read by admin/portal chrome? |
|---|---|---|---|
| brand_accent_color | settings.php:495-500 (UI L3686) | yes. It becomes color.accent, surface.accent and text.accent (ThemeResolver.php:70-71) | yes (ui_components.php:547, 619-629; portal_shell.php:170-200) |
| brand_button_text_mode | settings.php:503-506 (UI L3697) | **no** | yes, as `--on-accent` (ui_components.php:558, 623) |
| brand_font_family | settings.php:518-523 (UI L3737) | **no** | yes, as `--font-sans/--font-display` (ui_components.php:564-567, 631-634) |
| brand_border_radius | settings.php:525-529 (UI L3749) | **no** | yes, as `--radius-*` (ui_components.php:572, 635-641) |
| sidebar_theme | settings.php:508-513 (UI L3711) | no (n/a) | yes (ui_components.php:651-690) |
| brand_text_color (ink) | **none in the repo** | read, becomes text.primary (TenantBranding.php:38; ThemeResolver.php:72) | no |
| brand_canvas_color (pageBg) | **none** | read, becomes surface.page | no |
| brand_heading_font | **none** | read, becomes font.heading | no |
| brand_body_font | **none** | read, becomes font.body | no |

Repo-wide grep found these 4 keys only in `src/Services/Content/TenantBranding.php`.

**Brand-derived chrome variables** (not Studio):
- `--accent`, `--accent-deep`, `--accent-hover`, `--accent-soft`, `--on-accent`, `--accent-ink`, `--ring`, `--info`, `--info-soft` (ui_components.php:618-629; base L792).
- `--font-sans`, `--font-display` (base Inter, L845-847).
- `--radius-sm`, `--radius`, `--radius-lg`, `--radius-xl`, `--radius-2xl` (base L811-815).
- `--sidebar-*`.

The contrast derivation is `slate_brand_accent_tokens` (brand_tokens.php:36-82), shared with the portal (portal_shell.php:116-119).

---

## 5. Findings

| ID | Sev | Finding | Evidence | Remediation |
|---|---|---|---|---|
| T-H1 | High | **The mobile Theme sheet cannot save.** It edits `color.primary/secondary/background/surface/border/text`. None is a supported token, so `saveTokens` rejects the whole write (`unknown_token`). Its brand fields are local state only: site name defaults to "Northstar", logo buttons call `alert('Media Library')`, and colour mode is unused. It is reachable from the mobile dock "Theme" tab, and no functional test covers it. | ThemeBottomSheet.jsx:13-20, 28-29, 63-78, 118-124; StudioShell.jsx:905-910; MobileDock.jsx:25; StudioThemeService.php:140-147 | Replace the sheet body with `SiteSettingsForm` (as ThemeDialog does), or remove the tab. |
| T-H2 | High | **The branding layer is mostly dead.** Studio reads `brand_text_color`, `brand_canvas_color`, `brand_heading_font` and `brand_body_font`, but nothing writes them. The Branding form's font and radius never reach Studio. The Site settings note still says "Site branding (colours and fonts from Settings › Branding) applies first". | TenantBranding.php:36-42; ThemeResolver.php:70-76; settings.php:495-529; messages.mjs:1148 | Map `brand_font_family` and `brand_border_radius` (and optionally `--on-accent`) into BRANDING_TOKEN_MAP, or drop the unused keys and correct the note. |
| T-H3 | High | **Page widths hint is false.** The Site settings "Page widths" hint says each page picks Narrow/Normal/Wide/Full in Page settings. The page setting is inert. The tokens actually drive the document section's `layout.width`. | messages.mjs:518; PageInspector.jsx:177; StudioCompiler.php:99-102; StudioStylesheet.php:420-424 | Fix the hint text and/or give `settings.container_width` real CSS (see layout report F-H1). |
| T-M1 | Medium | **Radius/shadow presets ignore the tokens.** Presets `sb-radius-*` and `sb-shadow-*` are hard-coded. Changing radius.md, radius.lg or shadow.md in Site settings does not affect blocks using the Medium/Large preset (the default Border/Shadow controls). shadow.md default `0 4px 12px rgba(0,0,0,.08)` ≠ preset md `0 4px 6px -1px …`. | StudioStylesheet.php:241-242; StyleControls.jsx:177-180, 489, 527; ThemeResolver.php:48-53 | Express the presets as `var(--sb-radius-*, fallback)`, and add the xl/2xl/none tokens or map them. |
| T-M2 | Medium | **Three spacing systems with clashing names.** The `space.*` tokens (.75/1.5/3rem) are used only by `spacing_token`. The prop/section scale `sb-pad-*` (md = 2rem) and gap scale (md = 1rem) are separate and hard-coded. So "md" means 3 different sizes. | ThemeResolver.php:54-56; StudioStylesheet.php:32-33 | One token-backed spacing scale. |
| T-M3 | Medium | **Only section widths are tokenised.** `layout.*` tokens apply only to `.sb-w-*`. `layout.section` (80/48rem) and `layout.container` (75/48rem) are hard-coded with their own vocabulary (boxed/narrow, constrained/compact). | StudioStylesheet.php:166-182 vs 420-424 | Bind them to `--sb-layout-*`. |
| T-M4 | Medium | **Studio primary button contrast is not derived from the accent.** It uses `--sb-text-inverse` (#fff) on `--sb-surface-accent` (brand accent). A light brand gives an unreadable button. The admin and portal already solve this with `slate_brand_accent_tokens` (`--on-accent`), but Studio does not use it. | StudioStylesheet.php:67; ThemeResolver.php:70-76; brand_tokens.php:36-82 | Feed `text.inverse`, or a new `text.on_accent`, from the derivation when the branding accent is used. |
| T-M5 | Medium | **Undefined variables are referenced.** `--sb-text-secondary` and `--sb-text-default` are not tokens. They always render their hard-coded fallback and cannot be edited. Naming is inconsistent with text.primary/muted. | StudioStylesheet.php:258, 266; ThemeResolver.php:63-66 | Use `--sb-text-muted` / `--sb-text-primary`. |
| T-M6 | Medium | **Hidden accent default.** A tenant who saves the Branding form without choosing a colour stores `#111111` (the form default). That overrides Studio's neutral accent #1f2937. The admin chrome treats `#111111` as "unset"; Studio does not. | settings.php:911-912, 496-500; ui_components.php:578; ThemeResolver.php:94-101 | Store '' for the default, or apply the same sentinel in TenantBranding. |
| T-M7 | Medium | **Tokens with no default consumer.** surface.muted, surface.inverse, text.accent, radius.sm, radius.full, shadow.sm and space.* have no consumer in the base stylesheet. They appear in Site settings as global values but affect nothing until a block picks them. | StudioStylesheet.php (grep, §2) | Use them in base components, or label them "for blocks that choose this token". |
| T-L1 | Low | **Namespace collision.** Studio admin screens define `--sb-accent/--sb-border/--sb-text/--sb-muted` in the same `--sb-` namespace as site tokens. The pages are separate, so they do not clash today, but the names are misleading. | admin/_nav.php:107-112 | Rename to `--sbadm-*`. |
| T-L2 | Low | **Admin font sentinel mismatch.** The Branding form defaults to `system`, but the emitter treats `inter` as "no override". Saving the form untouched switches the admin font from Inter to the system stack. | settings.php:925-927; ui_components.php:579, 845 | Use the same default on both sides. |
| T-L3 | Low | **Stale comments** say brand font/radius have "no downstream consumer". `ui_components.php` does consume them. | settings.php:515-517, 921-924 vs ui_components.php:564-641 | Update the comments. |
| T-L4 | Low | **Stale docblock.** TenantBranding says its original callers (editor theme resolver) were removed. ThemeResolver still calls `resolve()`. | TenantBranding.php:10-13; ThemeResolver.php:182-198 | Update the docblock. |
| T-L5 | Low | **No per-device token values** (for example, a smaller `layout.*` or `space.*` on mobile). | ThemeResolver.php (flat map) | Optional: device-scoped token sets. |
| T-L6 | Low | **`settings.token_group` cannot be chosen in the UI.** Groups other than `default` arrive only through package import or the API. | SiteSettings.jsx:57; PageInspector.jsx (no control) | Expose it or document it. |
| T-I1 | Info | `compiled_css_vars` is stored but deliberately never trusted or emitted. | StudioThemeService.php:99; ThemeResolver.php:19-20 | n/a |
| T-I2 | Info | Token values pass the guard on both write and read. Unsafe values are dropped. | StudioThemeService.php:121-165; ThemeResolver.php:155-179 | n/a |
| T-I3 | Info | Editor skin `--sbx-*` (64 variables) is separate from site tokens and ratchet-tested. | builder.css:4-93; tests/css-tokens.test.mjs | n/a |
| T-I4 | Info | The token-to-variable name mapping exists twice (ResolvedTheme and StyleSurface), kept equal by a unit test. | ResolvedTheme.php:60-63; StyleSurface.php:356-361 | n/a |

## 6. Duplicates and conflicts at a glance

- **Radius:** `radius.*` token, `sb-radius-*` preset, inline literal radius, `border.radius_corners` (scoped) and admin `--radius-*`. That is 5 sources.
  - Order at runtime: inline > scoped corners > token class (`sb-rad--*`, collector) > preset class (base stylesheet).
- **Shadow:** `shadow.*` token, `sb-shadow-*` preset, inline literal shadow and the `style.shadow` object (scoped).
- **Spacing:** `space.*`, `sb-pad-*`, `sb-py-*`, `sb-gap-*`, `style.padding/margin`, `section.style.padding`.
- **Width:** `layout.*` token, page `container_width` (inert), section `layout.width`, `layout.section.content_width`, `layout.container.width`, inline `dimensions.max_width`.
- **Accent:** `brand_accent_color`, which fans out to 3 Studio tokens plus the admin `--accent`. Each also has its own Studio override.
- **Font:** `font.*` tokens, unwritten `brand_heading_font/body_font`, and `brand_font_family`, which goes to the admin only.

## 7. Not verified

- Runtime values. No database access, so the stored `studiobuilder_tokens` and `settings` rows per tenant were not inspected.
- Whether `Database::setting($key)` with no tenant argument (TenantBranding.php:49) is tenant-scoped by an ambient context. `StudioCodePolicy` passes `$tenantId` explicitly. The two read paths may differ.
- Visual effect of `--sb-*` overrides in a browser. Nothing was run.
- Whether any external tool (MCP `wp_apply_globals`-style tools, imports) writes the 4 unwritten branding keys outside this repo.
- Font loading for the font tokens. Only font-family strings are stored; there is no font-file loading except head-snippet font links (StudioCodePolicy editor font links). The full font path was not traced.
