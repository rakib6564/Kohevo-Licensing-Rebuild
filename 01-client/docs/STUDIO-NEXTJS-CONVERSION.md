# Converting a Next.js project to Kohevo Studio

The conversion guide for AI coding agents and developers. Follow it from start to finish on any Next.js project, whatever its router, styling library or data source.

- **Checked against:** Kohevo `main` at `b53b949`, Studio stylesheet VERSION 50.
- **Source of truth:** where this guide and the code disagree, the code wins. Section 3.2 shows how to read the live block catalogue from the install you are converting to.
- **Tested:** the installer in Appendix D and the verifier in Appendix E ran on a clean install of that commit. They created tokens, a header and footer, pages, a content type, a single template and a redirect, and a second run converged. The JSON examples and the accept/refuse rules were run through the real validator. Advice on Next.js itself and the locale-path plan in 11.9 are guidance, not tested results.

---

## Contents

1. [What a conversion is](#1-what-a-conversion-is)
2. [The rules](#2-the-rules)
3. [Phase 0: Prepare](#3-phase-0-prepare)
4. [Phase 1: Inventory the Next.js project](#4-phase-1-inventory-the-nextjs-project)
5. [Phase 2: Capture the baseline](#5-phase-2-capture-the-baseline)
6. [Phase 3: Decide and map](#6-phase-3-decide-and-map)
7. [Phase 4: Build the foundation](#7-phase-4-build-the-foundation)
8. [Phase 5: Build the header, footer and templates](#8-phase-5-build-the-header-footer-and-templates)
9. [Phase 6: Build the pages](#9-phase-6-build-the-pages)
10. [Phase 7: Convert interaction and motion](#10-phase-7-convert-interaction-and-motion)
11. [Phase 8: Convert functionality and integrations](#11-phase-8-convert-functionality-and-integrations)
12. [Phase 9: Convert SEO and URLs](#12-phase-9-convert-seo-and-urls)
13. [Phase 10: Validate](#13-phase-10-validate)
14. [Phase 11: Deliver](#14-phase-11-deliver)
15. [Troubleshooting](#15-troubleshooting)
- [Appendix A: Document reference](#appendix-a-document-reference)
- [Appendix B: Style reference](#appendix-b-style-reference)
- [Appendix C: Block catalogue](#appendix-c-block-catalogue)
- [Appendix D: Reference installer](#appendix-d-reference-installer)
- [Appendix E: Verifier](#appendix-e-verifier)
- [Appendix F: Conversion report template](#appendix-f-conversion-report-template)

---

## 1. What a conversion is

A Next.js site is a JavaScript application. A Kohevo Studio site is a set of JSON documents that a PHP server renders to HTML and CSS. **Nothing from the Next.js runtime carries over.** You are rebuilding the site's content, design and behaviour in Studio's own blocks, not porting code.

| Next.js | Kohevo Studio |
|---|---|
| React components, JSX | Blocks in a JSON document, rendered by PHP |
| `app/` or `pages/` routes | Pages, each with one slug; content type route prefixes for nested addresses |
| `layout.tsx`, shared header and footer | Header and footer partials |
| Dynamic route such as `blog/[slug]` | A content type (or category), one page per item, and a single template |
| Tailwind, CSS Modules, styled-components | Design tokens plus each block's native style |
| `next/image` | Media library files and `media_ref` values |
| `next/font` | `font.*` tokens plus a font link in the head snippet |
| `next/link` | Plain links; Studio prefixes sub-folder paths itself |
| `generateMetadata`, `metadata` | The document's `seo` object and site SEO settings |
| `next.config` redirects | `studio_redirects` setting |
| `useState`, effects, event handlers | Native widgets (tabs, accordion, modal, off-canvas, carousel), or a custom widget |
| API routes, Server Actions | Forms, Payment and Shop modules; otherwise an external service or a plugin |
| `fetch` at build, ISR | Content imported into pages; module widgets for live data |
| Client-rendered personalised pages | Not supported on public pages; see section 11 |

**The result is finished when:**

- every route is at the agreed address and looks and behaves like the original at every width;
- an editor can change the text, images and styles in the Studio editor;
- custom CSS is empty or holds only the gaps you listed in the report;
- nothing depends on the Next.js app or its Node server.

---

## 2. The rules

These apply through the whole conversion.

1. **Native first.** Use, in this order: a dedicated block, then block props, then native style (`style`, `responsive`, `style_states`, `style_parts`), then tokens. Custom CSS and custom widgets are last resorts, and each one is a line in the report.
2. **Rendered output is the truth.** The source code tells you what exists. The running site tells you what it looks like. Compare against the running site, not against a reading of the JSX.
3. **Use real content.** Copy text, alt text, links and SEO values exactly. Never invent copy, translate it or tidy it unless the owner asks.
4. **Keep every URL,** or redirect it with a 301.
5. **Build by script.** Everything is produced by one idempotent installer kept in version control. Hand edits in the editor are lost at the next run unless they go back into the script.
6. **Go through the application service.** Pages are written with `createPage`, `saveDraft` and `publish`, so they are validated, revisioned and compiled. Never write `document_json` to the database.
7. **Validate continuously.** A document either passes `ValidatedDocument::from()` or does not ship. Don't weaken a value to get past the validator without noting it.
8. **Never touch production without approval.** Work in a sandbox. Take a backup before any install. Don't deploy, push, delete or overwrite a live site unless you were told to.
9. **Don't copy secrets.** Environment values stay out of the document, the installer and the report. Ask the owner to enter API keys in the admin.
10. **Report gaps honestly.** If something can't be native, can't be converted or wasn't tested, say so in the report.

---

## 3. Phase 0: Prepare

### 3.1 What you need

- The Next.js source, its environment variable names (not values) and a way to run it.
- A Kohevo install to convert into, on a sandbox, with `studio-builder` active. Add `forms`, `stripe-payment`, `shop`, `booking` or `membership` if the project needs them.
- Node and Playwright for capture and verification.
- PHP CLI access to the Kohevo site root.

### 3.2 Read the target's capabilities

The catalogue changes between versions, and plugins add blocks. Dump the live catalogue before you plan. Run this from the Kohevo site root:

```php
<?php
// capabilities.php: php capabilities.php > capabilities.json
require getcwd() . '/config.php';
PluginLoader::activePlugins();
$rt = \Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory::build();
echo json_encode(array_map(static fn(array $m): array => [
    'type' => $m['type'], 'label' => $m['label'], 'version' => $m['version'], 'children' => $m['allows_children'],
    'entitlement' => $m['required_entitlement'] ?? null, 'parts' => $m['style_parts'] ?? [],
    'defaults' => $m['default_props'], 'fields' => $m['field_schema'],
], $rt->registry->editorManifests()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
```

Check the licence too: a block with an `entitlement` shows as unavailable unless the licence includes it.

### 3.3 Choose how to write into Studio

| Route | Use it for | Limits |
|---|---|---|
| **PHP installer** (recommended) | Whole sites | Needs shell access; runs as an admin actor |
| Package import (`importPackage`) | Moving finished pages between sites | 25 items per package, 250 media per item |
| HTML import (`importHtml`) | A rough first draft of one page | One draft page; 512 KB HTML, 128 KB CSS; maps to at most 4 levels, so the result is approximate and needs rebuilding by hand |
| MCP tools (`studio_*`) | Small edits by an AI client | Up to 50 operations per call; **there is no publish tool**, so a person publishes in the editor |
| Editor by hand | Final adjustments | Slow, and lost at the next installer run unless copied back |

Use the installer unless there is a reason not to. `react-site-bridge` is not an option: `bin/create-react-site.php` still refers to it, but the plugin isn't in the source tree.

### 3.4 Exit gate

- [ ] Next.js site runs locally or is reachable.
- [ ] Sandbox runs and you can log in.
- [ ] `capabilities.json` saved.
- [ ] Empty conversion folder created (section 4.3).

---

## 4. Phase 1: Inventory the Next.js project

### 4.1 Identify the stack

```bash
cat package.json
ls app src/app pages src/pages public middleware.* next.config.* tailwind.config.* 2>/dev/null
grep -rlE "getServerSideProps|getStaticProps|getStaticPaths|generateStaticParams" app src pages 2>/dev/null
grep -rl "use client" app src 2>/dev/null | wc -l
grep -rhoE "from ['\"][^.'\"][^'\"]*['\"]" app src pages components 2>/dev/null | sort | uniq -c | sort -rn | head -60
grep -rhoE "process\.env\.[A-Z0-9_]+" app src pages lib 2>/dev/null | sort -u
```

### 4.2 What to record

| Area | Find | Where |
|---|---|---|
| Router | App Router, Pages Router or both | `app/`, `pages/` |
| Rendering | Static, SSR, ISR, client-only per route | Data functions, `revalidate`, `dynamic`, `"use client"` |
| Routes | Every route, including dynamic, catch-all, grouped, parallel, intercepting | Folders; `next-sitemap`, `sitemap.ts`, the built output |
| Layouts | Root and nested layouts, route groups, templates | `layout.*`, `_app.*`, `_document.*` |
| Special pages | `not-found`, `error`, `loading`, `global-error` | `app/`, `pages/404.*` |
| Components | Each component, where it's used, what state and effects it has | `components/`, `src/` |
| Styling | Tailwind config, global CSS, modules, CSS-in-JS, UI kit (shadcn, MUI, Chakra) | Config and imports |
| Design values | Colours, fonts, sizes, spacing, radii, shadows, breakpoints, dark mode | Tailwind theme, CSS variables |
| Assets | `public/`, remote images, video, icons, fonts | `next.config` `images`, imports |
| Content sources | MDX, JSON, headless CMS, database, external APIs | `lib/`, `content/`, data functions |
| Forms | Fields, validation, submit target, emails | Components, API routes |
| Server code | API routes, Route Handlers, Server Actions, webhooks, cron | `app/api`, `pages/api` |
| Auth | NextAuth, Clerk, Supabase, custom; protected routes | `middleware.*`, providers |
| Commerce | Stripe, Shopify, carts | Dependencies |
| Motion | Framer Motion, GSAP, CSS animation, scroll effects | Dependencies, `@keyframes` |
| i18n | Locales, routing, translation files | `next.config`, `next-intl` and similar |
| SEO | Metadata, Open Graph, JSON-LD, sitemap, robots, canonicals | `metadata`, `sitemap.*`, `robots.*` |
| Middleware | Redirects, rewrites, geolocation, A/B tests | `middleware.*`, `next.config` |
| Third-party scripts | Analytics, chat, tag managers, pixels, cookie banners | `next/script`, `<Script>` |

### 4.3 Output

Create this folder and fill `inventory.json` and `routes.csv`:

```text
conversion/
  inventory.json      facts from the table above
  routes.csv          source URL, type, rendering, data source, target slug, target type, status
  component-map.csv   component, used on, target blocks, native or gap
  tokens.json         design values mapped to tokens
  content/            one JSON file per page or item (text, alt, SEO)
  baseline/           screenshots and computed styles from Phase 2
  build/              install.php and helpers
  media/              every asset the site needs
  report.md           Appendix F
```

### 4.4 Exit gate

- [ ] Every route found. Check `.next/server/`, `sitemap.xml` and a crawl agree.
- [ ] Every dynamic route expanded to its real URLs.
- [ ] Every component, form, API route, third-party script and environment variable name listed.

---

## 5. Phase 2: Capture the baseline

Run the production build, not `next dev`: `npm run build && npm run start`.

For every route, at widths 360, 390, 480, 600, 768, 900, 1024, 1280, 1440 and 1920, capture:

1. a full-page screenshot, after scrolling the whole page so lazy content loads;
2. the rendered text, in order;
3. the `title`, description, canonical, robots, Open Graph tags and JSON-LD;
4. all links and image sources with their alt text;
5. computed styles of key elements: `font-family`, `font-size`, `line-height`, `font-weight`, `color`, `background`, `padding`, `margin`, `border-radius`, `box-shadow`, `gap`, grid columns.

For interactive components, also capture their states: menu open, tab selected, modal open, hover, focus, form error and form success. Reduce motion before you compare screenshots:

```js
await page.emulateMedia({ reducedMotion: 'reduce' });
```

Extract content from rendered HTML, because that resolves CMS data, MDX and i18n. Use `<main>` so the header and footer aren't repeated on every page.

**Exit gate:** a screenshot and a content JSON exist for every route.

---

## 6. Phase 3: Decide and map

### 6.1 Classify each feature

| Class | Meaning | Action |
|---|---|---|
| **Native** | A block or module does it | Use it |
| **Approximate** | Native gets close, and the difference is small | Use it and report the difference |
| **Gap** | Needs custom CSS or a small script | Use the smallest fix and report it |
| **Widget** | Needs a custom PHP widget | Build one (section 11.8) and report it |
| **External** | Stays as a separate service | Link or post to it and report it |
| **Blocked** | Can't work on public Studio pages | Stop and ask the owner before going further |

**Ask the owner before building when any of these is present.** Each can make Studio the wrong target for that part of the project:

- user-specific pages or dashboards;
- a client-side application: editors, configurators, realtime, maps with live data;
- content that must stay live from an external CMS or API;
- server code with no module equivalent;
- locale-based URL routing that must be kept exactly.

A hybrid is often the right answer: Studio for the marketing site, and the application stays on its own subdomain.

### 6.2 Map routes

Studio page addresses have **one slug segment**: `/about`, never `/about/team`. Deeper addresses come from a content type with a route prefix of 1 to 3 segments.

| Next.js route | Studio target |
|---|---|
| `/` | Page with route mode `homepage` |
| `/about` | Page, slug `about` |
| `/about/team` | Content type with route `/about`, page slug `team` |
| `/blog` | Page with a Query Loop |
| `/blog/[slug]` | Content type with route `/blog`, one page per post, a category, a single template |
| `/docs/[...slug]` | Content type per folder level, up to 3 prefix segments; flatten anything deeper and redirect |
| `/shop/[id]` | Shop module, or a `produit`-style content type if there's no cart; note that `shop` is a reserved address |
| Route groups `(marketing)` | Ignored in the URL; use conditions on the header and footer if the groups have different layouts |
| `not-found` | Theme template of type `not_found` |
| `error`, `loading` | No equivalent; drop them and note it |
| `api/*` | Section 11 |
| Parallel and intercepting routes | Rebuild as a modal or a separate page; report it |

**Slug rules:**

- pattern `^[a-z0-9]([a-z0-9-]{0,189}[a-z0-9])?$`;
- these slugs and first prefix segments are reserved: `403`, `404`, `500`, `account`, `admin`, `ai-assistant`, `api`, `assets`, `audit`, `bin`, `book`, `booking`, `claude`, `coaching`, `config`, `cron`, `customer`, `data`, `db`, `db-backups`, `dev-server`, `docs`, `forms`, `includes`, `index`, `install`, `lang`, `licensing`, `login`, `logout`, `mcp`, `mcp-gateway`, `media`, `media-library`, `member`, `membership`, `multilang-translate`, `plugins`, `preview`, `public`, `register`, `robots`, `route`, `shop`, `sitemap`, `src`, `storefront`, `stripe-payment`, `studio`, `studio-builder`, `templates`, `tests`, `uploads`, `well-known`;
- a route prefix can't start with `category`, `tag`, `author` or `search`.

If the original uses a reserved address, give the page a new slug and record the change. A 301 can't rescue it, because Studio never sees requests for platform addresses. For example, `/register` and `/login` belong to the platform.

Turn on the trailing-slash setting (`studio_url_trailing_slash` = `1`) only when the original uses `trailingSlash: true`.

### 6.3 Map components

Most components become a small group of blocks. Build one PHP helper per component, the way `CARD()` and `SEC()` work in Appendix D, so that repeated design stays in one place.

| Component | Blocks |
|---|---|
| Section wrapper | Section with `layout.container` (see 9.1) |
| Stack, Card | `core.container` with native style; `core.card` for simple cards |
| Grid | `layout.grid` |
| Flex row | `layout.flex` |
| Heading, Text | `core.heading`, `core.text` |
| Markdown or MDX body | `core.rich_text`, or split into headings, text and images |
| Button, Link | `core.button`, `core.link` |
| Image | `core.image` (`media_id`) |
| Icon | `core.icon` if it's in the 38 built-in names; otherwise an SVG image |
| Navbar | Header partial with `core.nav_menu` and `layout.offcanvas` |
| Accordion, Tabs | `core.accordion`, `core.tabs` |
| Modal, Dialog | `core.modal` |
| Drawer, Sheet | `layout.offcanvas` |
| Carousel | `core.carousel` |
| Gallery with lightbox | `core.gallery` (with 2 or more images) |
| Stat counters | `core.stats` |
| Pricing table | `layout.grid` of containers, `core.table` or `core.feature_list` |
| Testimonials | `core.quote`, `core.carousel` |
| Timer | `core.countdown` |
| Video | `core.video` |
| Map | `core.map` |
| Table | `core.table` |
| Blog list | `core.query_loop` |
| Form | `core.form` and `core.form_field` |
| Pay button | `payment.button`, `payment.link` |
| Products | `shop.*` |

**Check limits while you plan.** A document holds at most 250 blocks, 50 sections and 6 levels of nesting. Split long pages sensibly, and flatten wrappers that only exist for CSS. If a design needs one more wrapper level, set the layout on an existing block with `style.layout`.

### 6.4 Exit gate

- [ ] Every route has a target and an owner decision if it's blocked.
- [ ] Every component has a target and a class.
- [ ] Owner has confirmed anything Blocked or External.

---

## 7. Phase 4: Build the foundation

### 7.1 Tokens

Set design values once, as tokens, using `saveDesignTokens`. Keep the default token group.

| Token | Meaning |
|---|---|
| `surface.page`, `.primary`, `.secondary`, `.muted`, `.accent`, `.inverse` | Backgrounds |
| `text.primary` | Main text; also the fallback for body text |
| `text.body` | Body text colour, when different from `text.primary` |
| `text.heading` | Heading colour; default `currentColor` follows the surrounding text |
| `text.muted`, `.accent`, `.inverse` | Other text colours |
| `color.accent` | Accent, links |
| `border.default` | Default border |
| `font.heading`, `font.body` | Font stacks |
| `radius.sm`, `.md`, `.lg`, `.full` | Corners |
| `shadow.sm`, `.md` | Shadows |
| `space.sm`, `.md`, `.lg` | Spacing scale |
| `layout.content` | Site content width; boxed sections follow it |
| `layout.narrow`, `.normal`, `.wide` | Named widths |
| `type.body_size`, `type.body_leading` | Body text size and line height |
| `type.heading_leading` | Heading line height |
| `type.flow` | Space after text, lists, menus and icons, and above button rows |
| `type.heading_gap` | Space under headings |
| `type.section_gutter` | Section side space |
| `type.smoothing` | `auto` or `antialiased` |

**Set `type.flow`, `type.heading_gap` and `type.section_gutter` to `0`** on a converted site. Tailwind and most CSS resets have no default element margins, so exact spacing should come from each block's own margin and padding. Leaving the defaults adds space you'll then chase page by page.

Token values are sanitised, and invalid ones are dropped silently:

| Category | Accepts |
|---|---|
| Colours | hex, `rgb()`, `rgba()`, `hsl()`, `hsla()`, `transparent`, `currentColor`. Convert `oklch()` to hex first |
| `radius`, `space` | Lengths |
| `shadow` | `none` or 2 to 4 lengths followed by a colour |
| `font` | Letters, digits, spaces, commas, quotes, hyphens |
| `layout` | A positive length in px, rem or em |
| `type.*_leading` | A number or a length |
| Other `type.*` sizes | A length with its unit, or `0` |

Dark mode: tokens have one value set, and there's no visitor light/dark switch. Convert the default theme and report the other one.

### 7.2 Fonts

Studio has no font upload. Add the font link to the head snippet and name the family in the token:

```php
Database::setSetting(StudioCodePolicy::SETTING_HEAD_SNIPPET, StudioCodePolicy::prepareSnippet($fontLinks, 'head'), $tid);
```

- Head snippets accept only `script`, `noscript`, `style`, `link` and `meta`, up to 32 KB.
- The editor canvas loads fonts only from `fonts.googleapis.com`, `fonts.bunny.net`, `use.typekit.net` and a few similar hosts. See `EDITOR_FONT_HOSTS`.
- `next/font/google` maps directly to a Google Fonts link. Request only the weights in use.
- For local fonts, put the files in `uploads/`, and add an `@font-face` to custom CSS as a reported gap. Use a full URL: Studio doesn't adjust CSS `url()` paths on a sub-folder install.

### 7.3 Media

Copy each asset into `uploads/media/<site>/` and register it with `Media::register()`. This gives it a media id.

- Download remote images at build time. Don't hot-link the old host.
- Use the original resolution, not the `/_next/image` output.
- Blocks with `media_ref` fields refer to `{media_id, alt, focal_point}`; the id is the media row's id, so look it up by path and never hard-code it.
- Images take their `alt` from the original. Decorative images use `alt: ""`.
- Studio doesn't generate `srcset`. Resize heavy images before import, and check page weight in Phase 10.

### 7.4 Site settings

| Setting | Purpose |
|---|---|
| `site_name` | Site name |
| `studio_url_trailing_slash` | `1` for trailing slashes |
| `studio_seo_title_format` | `%title%`, or `%title% \| %site_name%`. Used only for pages with no SEO title of their own |
| `studio_seo_description`, `studio_seo_robots` | Site defaults |
| `brand_accent_color`, `brand_logo_path`, `brand_logo_dark_path`, `brand_favicon_path`, `brand_login_image_path`, `brand_login_tagline` | Admin and login branding, plus the favicon |
| `studio_custom_post_types`, `studio_meta_fields` | Content types and fields |
| `studio_redirects` | Redirects |
| `studio_menus` | Site menus; write it through `StudioMenus::save()` |

### 7.5 Content types

One content type per dynamic route family. Each is an entry in `studio_custom_post_types`:

```php
$types = PageMeta::postTypes($tid);
$types['service'] = ['key' => 'service', 'singular' => 'Service', 'plural' => 'Services', 'route' => '/services', 'is_system' => false, 'description' => '...'];
```

- Fields are entries in `studio_meta_fields`. Types include `text`, `textarea` and other groups for numbers, choices, media, dates and relations: see `MetaFieldTypes::all()`.
- Assign a page and its values with `PageMeta::save($tid, $pageId, 'service', $values)`.
- Show values in templates with `theme.field_value`.
- Call `PageRoutes::forget($tid)` after changing types.

### 7.6 Exit gate

- [ ] Tokens saved, and the values read back unchanged.
- [ ] Fonts load on a test page.
- [ ] All media registered, with no missing files.

---

## 8. Phase 5: Build the header, footer and templates

### 8.1 Header and footer

The site-wide partials are `header_partial` and `footer_partial` pages with slug `default`.

- **Navigation:** use `core.nav_menu`. Set `items` for up to 12 links, or put the links in a site menu with `StudioMenus::save()` and set `menu` to its key, which also gives you sub-menus, up to 60 links.
- **Current link:** style the `current` part; the page script marks the link.
- **Mobile menu:** `layout.offcanvas` with its trigger as a hamburger (`trigger_icon: menu-short`, `trigger_show_text: false`, `trigger_variant: plain`). Hide the desktop nav with `responsive.tablet.hide` **and** `responsive.mobile.hide`. Each `hide` covers only that device.
- **Sticky header:** set `sticky_header` in the partial's settings to pin the whole header:

  ```json
  {"edge": "top", "shadow": true, "hide_on_scroll": false, "devices": ["desktop", "tablet", "mobile"]}
  ```

  To pin only one row of a header, put `interactions.sticky` on that section's block instead.

### 8.2 Different headers per section of the site

Add more partials with `settings.conditions`. The `default` partial is a fallback that's never scored, so conditions on it do nothing. To stop a conditioned partial applying somewhere, exclude those slugs in its own rules. A page can also set `header_mode` or `footer_mode` to `inherit`, `custom` or `hidden`.

### 8.3 Theme templates

Each dynamic route family gets a single template, stored twice: as a `page_template` and as a `system` page. Appendix D shows both.

```php
$rules = [['type' => 'include', 'condition' => 'post_type', 'value' => 'service']];
```

- Use `theme.post_title`, `theme.post_content`, `theme.post_meta` and `theme.field_value`.
- Template types: `single`, `archive`, `search`, `not_found`.
- The most specific match wins.
- Make a `not_found` template for a custom 404.

**Exit gate:** the header, footer and each template render on a test page at all widths.

---

## 9. Phase 6: Build the pages

### 9.1 The section pattern

Every section is a full-width Studio section holding one `layout.container`. The container's `content_width: boxed` holds its content to `layout.content`, while its background runs the full width. This avoids the old `max-w-7xl mx-auto px-6` wrapper.

```json
{
  "id": "sec_0123456789abcdef0123",
  "label": "Features",
  "global_ref": null,
  "layout": {"background_token": "surface.primary", "columns": {"base": 1, "md": 1}, "gap": "none", "padding_y": {"base": "none"}, "width": "full"},
  "visibility": {"auth_state": "any", "devices": ["base", "sm", "md", "lg"]},
  "blocks": [{
    "id": "blk_0123456789abcdef0123",
    "type": "layout.container",
    "version": 1,
    "props": {"width": "full", "padding": "none", "alignment": "center"},
    "style": {
      "align": {"base": "left"},
      "layout": {"content_width": "boxed"},
      "padding": {"top": "80px", "bottom": "80px"},
      "background": {"color": "#f4f4f5"}
    },
    "responsive": {
      "tablet": {"style": {"padding": {"left": "24px", "right": "24px"}}},
      "mobile": {"style": {"padding": {"top": "56px", "bottom": "56px", "left": "18px", "right": "18px"}}}
    },
    "visibility": {"auth_state": "any", "devices": ["base", "sm", "md", "lg"]},
    "bindings": [],
    "children": []
  }]
}
```

- Side padding on a boxed block is calculated. Set it again on tablet and mobile, or content touches the screen edge.
- Boxed content that isn't the default width: set `content_max`, for example `min(720px, calc(100% - 64px))`.
- Sections laid into a template's `theme.post_content` must not be boxed, or they're inset twice. Use `content_width: full`.

### 9.2 Styling is desktop-first

Tailwind is mobile-first. Studio's base style is **desktop**, and overrides go down.

| Tailwind | Studio |
|---|---|
| `lg:` / `xl:` / `2xl:` value | Base `style` |
| `md:` value | `responsive.tablet` |
| Unprefixed or `sm:` value | `responsive.mobile` |

Studio has two override ranges: tablet up to 1023.98px and mobile up to 767.98px. Designs with more breakpoints need a choice: use `clamp()` for fluid sizes, pick the value that suits most of the range, and check the widths around each lost breakpoint. A design that switches at 1024 exactly needs a check there, because 1024 counts as desktop.

Example: `text-3xl md:text-5xl lg:text-6xl font-bold mb-4` becomes:

```json
{
  "style": {
    "align": {"base": "left"},
    "typography": {"size": "60px", "weight": "bold"},
    "margin": {"bottom": "16px"}
  },
  "responsive": {
    "tablet": {"style": {"typography": {"size": "48px"}}},
    "mobile": {"style": {"typography": {"size": "30px"}}}
  }
}
```

### 9.3 Tailwind to native

| Tailwind | Native |
|---|---|
| `flex flex-col gap-4` | `layout.flex` or `core.container`; `style.layout.gap: "16px"` |
| `justify-between items-center` | Flex props `justify: between`, `align: center` |
| `grid grid-cols-3` | `layout.grid` prop `columns: 3` |
| `md:grid-cols-2 grid-cols-1` | `responsive.tablet.props.columns`, `responsive.mobile.props.columns` |
| `grid-cols-[2fr_1fr]` | `style.layout.tracks: "2fr 1fr"`, and `responsive.<device>.style.layout.tracks` |
| `col-span-2`, `col-span-full` | `style.layout.column_span: 2` or `"full"` |
| `row-span-2` | `style.layout.row_span: 2` |
| `p-6`, `px-4`, `mt-8` | `style.padding`, `style.margin`, per side, in px |
| `w-full`, `h-64`, `min-h-screen`, `max-w-xl` | `style.dimensions`: `width`, `height`, `min_height`, `max_width` |
| `aspect-video` | Image prop `aspect_ratio: "16:9"`, or `dimensions.aspect_ratio: "16/9"` |
| `overflow-hidden`, `object-cover` | `dimensions.overflow`, `dimensions.object_fit` |
| `text-lg font-semibold leading-tight tracking-wide uppercase` | `style.typography`: `size`, `weight`, `line_height`, `letter_spacing`, `transform` |
| `text-slate-600` | `style.color` (a hex value, or a token when it's a brand colour) |
| `bg-white`, `bg-gradient-to-r` | `style.background.color`, `style.background.gradient` |
| `rounded-xl` | `style.border.radius: "12px"` |
| `border border-slate-200` | `style.border`: `width`, `style`, `color` |
| `border-b` | `style.border.bottom`, or `border.sides: "bottom"` on a part |
| `shadow-lg` | `style.shadow` object |
| `absolute top-4 right-4 z-10` | `style.position` plus `style.z_index` |
| `sticky top-0` | `interactions.sticky` |
| `hidden md:block` | `responsive.mobile.hide: true` |
| `hover:bg-x`, `focus:` | `style_states.hover`, `.focus` |
| `transition duration-200` | `style.effects.transition` |
| `hover:-translate-y-1`, `hover:scale-105` | `style_states.hover.effects.transform` |
| `backdrop-blur`, `blur`, `grayscale` | `style.effects.backdrop_blur`, `.filter` |
| `divide-y`, `space-y-*` | `gap`, or a part's `:not_first` state with a one-sided border |
| `container mx-auto` | Boxed `layout.container` |
| `prose` | `core.rich_text` with `style_parts` |

Colour conversion: use computed values from the baseline, not class names. Spacing: Tailwind's scale is 4px per step.

### 9.4 Reach inner elements with parts

`style` styles the block's wrapper. Typography and colour reach the block's own text. To style inner pieces, such as a button's background or a nav link, use `style_parts`. Blocks without parts in Appendix C can only be styled on the wrapper.

```json
{
  "style_parts": {
    "button": {
      "background": {"gradient": "linear-gradient(140deg,#ff4dae,#e9008a)"},
      "border": {"width": "0px", "radius": "999px"},
      "size": {"height": "52px", "min_width": "160px"},
      "padding": {"left": "28px", "right": "28px"},
      "layout": {"display": "inline-flex", "justify": "center", "align": "center"},
      "typography": {"family": "body", "size": "16px", "weight": "bold"},
      "color": "#ffffff",
      "effects": {"transition": {"duration_ms": 200, "easing": "ease"}}
    },
    "button:hover": {"effects": {"transform": {"translate_y": "-2px"}}}
  },
  "responsive": {
    "mobile": {"style_parts": {"button": {"size": {"height": "46px"}}}}
  }
}
```

Part rules are written with `!important`. Custom CSS can't override them, so an exception on top of a native border fails without warning. Use the part states instead: `hover`, `focus` and `not_first`.

### 9.5 Content

- Copy text exactly from the Phase 2 extraction.
- Heading highlight (`<span class="text-primary">word</span>`): use the `highlight` prop on `core.heading`, not two blocks.
- Keep the heading hierarchy: one `h1` per page. Set `level` from the original tag, then set size separately.
- `level: div` isn't a heading element, so it doesn't take `text.heading`. Set its colour.
- `core.text` default size `base` is a fixed size. Use `size: "inherit"` to follow the surrounding block.
- Rich text is sanitised. Convert MDX components to blocks first, and keep rich text for plain prose.
- Rich text field limit is 50,000 characters; plain text 20,000; strings 2,000; repeaters 50 items, and fewer on some blocks.
- Anchors: set `attributes: {"id": "pricing"}` on a block. Links `/#pricing` then work, and `[id]` already has a scroll margin of 5rem.
- `href="#"` isn't a valid URL; use `/#`.

### 9.6 Exit gate

- [ ] Every page validates and publishes.
- [ ] Block count under the limits on each page.
- [ ] No text differs from the baseline, by automated comparison.

---

## 10. Phase 7: Convert interaction and motion

**Studio's public pages run Studio's own script only.** React state, effects and event handlers don't carry over. Replace behaviour, don't port it.

| Original | Native |
|---|---|
| Mobile menu toggle | `layout.offcanvas` |
| Dropdown nav | Site menu with depth; `submenu`, `sublink`, `toggle` parts |
| Accordion, FAQ | `core.accordion` |
| Tabs | `core.tabs` |
| Modal | `core.modal` |
| Carousel, Swiper | `core.carousel`; check it can show the original's content, as slides have a fixed shape |
| Lightbox | `core.gallery`; `core.video` with `lightbox` |
| Sticky header, sidebar | `sticky_header`, `interactions.sticky` |
| Parallax | `interactions.parallax` |
| Hover effects | `style_states`, part `:hover` |
| Fade-in on scroll (Framer `whileInView`) | `animation`, on a block or section |
| Page transitions | None; drop and report |
| Count-up | `core.stats` |
| Countdown | `core.countdown` |
| Filter, sort, search on a list | `shop.products` has a filter; `theme.search_box`; otherwise a gap |
| Video on hover, scroll-driven timelines, GSAP, canvas, 3D | Gap or widget; simplify with the owner's agreement |

Entrance animation:

```json
{"animation": {"type": "fade_up", "duration_ms": 600, "delay_ms": 100, "easing": "ease-out"}}
```

Types: `fade_in`, `fade_up`, `fade_down`, `scale_up`, `slide_in`, `move_left`, `move_right`, `reveal_left`, `reveal_up`, `none`. Use `interactions.trigger` for `hover`, `focus`, `click`, `viewport-enter`, `scroll` or `load`.

Sticky and parallax:

```json
{
  "interactions": {
    "sticky": {"edge": "top", "offset": 0, "devices": ["desktop", "tablet"], "shadow": true, "background": "#ffffff"},
    "parallax": {"layer": "background", "speed": 30, "direction": "up", "devices": ["desktop"]}
  }
}
```

Hover movement runs only on devices that can hover, and only when the visitor allows motion. Keep that behaviour.

**Small scripts:** a footer snippet can add a script to public pages (`studio_code_footer_snippet`, `script` allowed, 32 KB). It doesn't run in the editor or in Preview. Use one only for small, reported behaviour, such as an analytics event, that doesn't change layout.

**Exit gate:** each interactive component works by mouse, keyboard and touch, and Escape closes any overlay.

---

## 11. Phase 8: Convert functionality and integrations

### 11.1 Rendering and data

Studio compiles a page at publish time and serves that result. Dynamic blocks such as Query Loop and module widgets render at request time.

| Next.js | Studio |
|---|---|
| Static, SSG | A published page |
| SSR with data that rarely changes | Import the data into pages |
| ISR | Import, and let editors maintain it; or re-run the installer on a schedule, with the owner's agreement |
| Live external data | A custom widget or a data provider in a plugin |
| Client-side fetching | Move to one of the above |

### 11.2 Content from a CMS or MDX

Import each entry as a page, with SEO, category, excerpt, date and field values. Set `published_at` to the original date. After the conversion, the content lives in Studio. **Confirm that with the owner**, since editors move from the CMS to Studio.

### 11.3 Blog

```php
B('core.query_loop', ['source' => 'posts', 'category' => 'Blog', 'columns' => 3, 'per_page' => 9, 'show_author' => false])
```

- **`source` doesn't select a content type.** The loop lists published `page` and `landing` pages and filters by `category`, `tag` and author. Without a category it lists every page on the site.
- Give each post `settings.category` and `settings.excerpt`.
- The card image is the page's `seo.og_image_media_id`.
- Style cards with parts: `card`, `image`, `title`, `excerpt`, `meta`, `button`, `grid`.
- A single template can match the category: `['type' => 'include', 'condition' => 'category', 'value' => 'Blog']`.

### 11.4 Forms

```php
B('core.form', ['form_name' => 'contact', 'submit_text' => 'Send', 'labels' => 'visible'], [
    B('core.form_field', ['field_type' => 'text', 'name' => 'name', 'label' => 'Name', 'required' => true, 'width' => '50']),
    B('core.form_field', ['field_type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true, 'width' => '50']),
    B('core.form_field', ['field_type' => 'textarea', 'name' => 'message', 'label' => 'Message']),
], ['id' => 'blk_' . substr(md5('site:contact-form'), 0, 24)]);
```

- A form with no `action` becomes a Forms module form when its page is published. The module handles validation, CSRF, spam protection, storage, notification email and webhooks.
- **Give each form a fixed block id.** The Forms entry is named after the block id, so a random id on each build creates a new entry each time and splits submissions.
- Set `notify_email`, or configure it in Forms. Without the module, set `action` to an external endpoint.
- `field_type` covers text, email, tel, number, url, textarea, select, checkbox, radio, checkboxes, date, time, file and hidden.
- Options are a comma-separated string.
- Prefill from a URL with `url_param` and `url_values`.
- Inline newsletter forms: `labels: hidden`, `layout: inline`.
- Zod or client-side rules beyond required and type become a reported difference.

### 11.5 API routes, Server Actions, webhooks

| Purpose | Target |
|---|---|
| Contact, newsletter | Forms; its webhooks can pass submissions on |
| Payments | Payment or Shop |
| Booking | Booking module |
| Membership | Membership module |
| Anything else | Keep it as an external service and call it from a form `action`, or write a plugin |

Don't leave a route that the page still expects. List each original API route in the report with its outcome.

### 11.6 Auth and personalisation

Public Studio pages are anonymous and cached. Blocks accept `visibility.auth_state` (`any`, `authenticated`, `guest`), but don't use it to protect content.

- Member areas: the Membership module, or keep the app on its own subdomain and link to it.
- NextAuth, Clerk and Supabase users don't migrate automatically. Treat user data as its own project, with the owner.
- Per-user dashboards are Blocked in section 6.1.

### 11.7 Payments and shop

- `payment.button`: fixed or visitor-chosen amount, once or monthly. Needs the Stripe plugin connected. It renders nothing until a provider is connected, and an empty block leaves no box on the public page.
- `payment.link`: a Stripe Payment Link.
- `shop.*`: product grid, product detail blocks, cart, checkout and confirmation. Needs the `shop` entitlement and plugin. Products are in Shop, not in pages.
- API keys go in the admin. Never put them in a script.
- Test in Stripe test mode. Live webhooks need setting up by the owner.

### 11.8 Custom widgets

If something can't be built natively and matters, write a widget in a plugin. See `docs/STUDIO-WIDGET-SDK.md`.

- Register with `Studio::widgets()->register([...])` and render on the server in PHP.
- Keep props in the schema so editors can change them.
- Use a plugin's `studio-widgets.php` for a module's widgets, so documents still validate when the plugin is off.
- There's no way to run a React component inside a page. Rewrite the UI in server HTML and CSS, or embed the separate app.

### 11.9 Internationalisation

Use the `multilang-translate` plugin, which switches language with `?lang=`. It's not the same as Next.js locale paths such as `/fr/about`.

To keep locale paths, make a content type per locale prefix (`fr`, and so on), with one page per translation. Slugs stay single-segment under the prefix. Test the plan on one page before committing to it, and note that this has not been proven at site scale.

Set the install's `default_language` for dates and interface text.

### 11.10 Third-party scripts

| Script | Where |
|---|---|
| Google Analytics | `studio_code_ga4_id` |
| Google Tag Manager | `studio_code_gtm_id` |
| Search engine verification | Structured verification setting |
| Pixels, chat, other tags | Head or footer snippet |

Scripts run on public pages only. Keep the cookie consent behaviour the original had.

### 11.11 Exit gate

- [ ] Every API route, integration and environment variable has an outcome.
- [ ] Forms submit, and the submission arrives where it should.
- [ ] Payments work in test mode.

---

## 12. Phase 9: Convert SEO and URLs

### 12.1 Metadata

```php
$doc['seo'] = ['title' => '...', 'description' => '...', 'canonical_url' => null, 'robots' => 'index,follow', 'og_image_media_id' => 12];
```

- Title up to 255 characters, description up to 1,000.
- `robots`: `index,follow`, `noindex,follow`, `index,nofollow`, `noindex,nofollow`.
- Set `title` on every page, exactly as rendered in the baseline. A page's own title is used as it is; the site title format applies only to pages without one.

### 12.2 URLs

- Export `next.config` `redirects()` and middleware redirects into `studio_redirects`: `{from, to, code}`, up to 500 rules. `code` is 301 or 302.
- Rules match paths only, ignoring case, query string and a trailing slash. Wildcards such as `/old/:slug*` must be expanded into one rule per address.
- Redirects can't take over platform addresses or `/`.
- `rewrites()` have no equivalent; move the page to the rewritten address or redirect.
- Old-slug behaviour: a page with a prefix answers `/slug` with a 301 to `/prefix/slug`.

### 12.3 Other

- Sitemap: Studio generates one. Check it lists what you expect.
- Structured data: Studio doesn't output JSON-LD from page fields. Site-wide JSON-LD can go in the head snippet. Per-page JSON-LD is a gap: report it.
- Compare every route's title, description, canonical, robots and Open Graph tags with the baseline.
- Theme template pages should be `noindex,nofollow`.
- The site robots setting can only make pages stricter: `noindex` there keeps every page out of search, whatever the page says. On a dev or staging host, keep `studio_seo_robots` at `noindex,follow` until launch, then change it.

### 12.4 Sub-folder installs

Studio adds the base path to root-relative `href`, `action`, `src` and `poster` values, and media URLs are absolute from `APP_URL`. Two things are not rewritten: `url()` in custom CSS, and links inside custom scripts.

**Exit gate:** every baseline URL returns 200 or a 301 to the right page, with no redirect chains.

---

## 13. Phase 10: Validate

Run all of these. Record results in the report.

### 13.1 Automated

1. **Documents:** `ValidatedDocument::from()` passes for every page, which the installer already does.
2. **Installer:** run it twice from a clean state. The second run must not create pages, forms or media.
3. **Verifier** (Appendix E): every route at every width, with no sideways scroll, broken images, missing alt, console errors or failed requests.
4. **Text comparison:** rendered text of each `<main>` against the baseline, after normalising whitespace.
5. **Metadata comparison:** section 12.3.
6. **Link audit:** every internal `href` and `src` returns 200.
7. **Redirects:** every old URL.
8. **Custom CSS:** report its bytes and `!important` count. The target is 0.

### 13.2 Visual

Compare screenshots side by side at 390, 768, 1024 and 1440 for every page type, and for every page on small sites. Look at spacing, alignment, line breaks, image crop, colour and font weight.

Check these widths specifically: just under and over each original breakpoint, plus 767, 768, 1023 and 1024.

Use a real pixel diff only as a guide, since fonts and sub-pixel layout differ. Don't chase 1px differences while a section is missing.

### 13.3 Behaviour

- Open and close each menu, drawer, modal and lightbox: by click, by backdrop, and by Escape.
- Keyboard: Tab through the page; focus is visible and trapped in overlays.
- Forms: empty, invalid and valid submissions.
- Sticky and scroll effects at phone and desktop width.
- Reduced motion.
- Touch devices don't keep hover states.

**Test in a real browser.** Mobile emulation in some panes freezes CSS scroll timelines, which makes animated content look invisible. If that happens, test the phone layout in a 390px iframe.

### 13.4 Performance and accessibility

- Compare Lighthouse for key pages against the original.
- Check image weight; there's no automatic resizing.
- One `h1`, ordered headings, alt text, form labels (hidden labels stay readable by screen readers), colour contrast.

### 13.5 Editor

Open each page type in the Studio editor. Change a text, an image and a colour; preview; undo. If a page is awkward to edit, the conversion isn't finished.

### 13.6 Definition of done

- [ ] All automated checks pass, or each failure is explained in the report.
- [ ] Every page type compared at four widths.
- [ ] All interactions and forms work.
- [ ] No request reaches the old Next.js host.
- [ ] Custom CSS empty, or every rule is justified in the report.
- [ ] Report complete and owner sign-off obtained.

---

## 14. Phase 11: Deliver

1. Package the installer folder: scripts, content, media, a README. It should read only inside its folder and need no network.
2. Make it check what it needs before changing anything: a Studio feature, an entitlement, writable `uploads/`.
3. **Get the owner's approval** to touch the target site.
4. Back up the database and `uploads/` on the target.
5. Run from the site root: `cd <site root> && php <kit>/install.php`. `config.php` is read from the current folder.
6. If the target already holds a site, decide with the owner what to archive or delete. Studio has `archivePage`, but no permanent page delete. Deleting means removing rows from compilations, dependencies, locks and revisions, then pages, in that order, plus `studio_page_meta_<id>` settings.
7. Run the verifier against the target.
8. Point the domain at the site. Keep the Next.js deployment available until the owner is satisfied.
9. Re-run on the live domain: redirects, forms, payments, analytics, sitemap. Set robots to indexable.
10. Hand over the report, editor notes and rollback steps.

**After a Studio upgrade** that changes rendering, republish the pages, by running the installer again. Compiled pages keep the old output until then.

---

## 15. Troubleshooting

### Validator errors

| Error | Cause | Fix |
|---|---|---|
| `invalid_block_type`, `No block type` | Plugin inactive or missing | Check `capabilities.json` |
| `unknown_property` in settings | Key isn't allowed | Use only `ALLOWED_SETTINGS_KEYS` |
| `invalid_style_value` on a part's `weight` | Numeric weight | Parts take `normal`, `medium`, `semibold`, `bold`, `extrabold` |
| `invalid_style_value` on a part's `line_height` | Unitless number | Parts need a length, such as `23px` |
| `invalid_style_value` on `layout.tracks` | `repeat()` or `calc()` | Write tracks out; use `columns` for equal tracks |
| `A device style cannot set ...` | Group isn't per-device | Device styles take font size, padding, margin, layout (not display or content width) and width, height, min height, max width only |
| `invalid_enum_value` on `aspect_ratio` | Ratio not in the list | Use `style.dimensions.aspect_ratio` |
| `Choose an image or a gradient, not both` | Both on one block | Put the gradient on `overlay`, or on another layer |
| `required_field` on `media` | No media id | Register the file first; check the path |
| Nesting or block-count error | Too deep or too big | Flatten; split the page |
| URL error on a link | `#` or an unsafe scheme | Use `/#`, or a real path |
| Unknown part | Block has no such part | Check Appendix C for the install's version |

### Rendering problems

| Symptom | Cause | Fix |
|---|---|---|
| Margins ignored | A custom CSS reset outranks the block's class | Wrap the whole reset selector in `:where()` |
| Extra space everywhere | Default element spacing | Set `type.flow` and `type.heading_gap` to `0` |
| Content touches the screen edge on phones | Boxed padding on small screens | Set side padding on tablet and mobile |
| Nav shows on phones | `tablet.hide` only covers tablet | Set `mobile.hide` too |
| Links turn the accent colour | Link blocks have their own colour | Set `style.color` on the block |
| Text size doesn't follow its parent | `core.text` size `base` | Use `size: "inherit"` |
| Heading colour wrong | `level: div`, or `text.heading` not set | Set `style.color` |
| Wide gallery tiles overflow on phones | Tile height plus an aspect ratio | Set the phone `item` height to `auto` |
| Query Loop lists every page | No category | Set `category` |
| Empty section still takes space | Layout with no children renders | Remove it, or give it a child that can be empty |
| Pay button missing | No provider connected | Connect the provider |
| Change doesn't appear | Page wasn't republished, or a cached compile | Republish |
| Duplicate Forms entries | Random block id | Use a fixed id |
| Images missing on a sub-folder install | Old code or CSS `url()` | Use a current build; use full URLs in CSS |

---

## Appendix A: Document reference

```json
{
  "schema_version": "1.0",
  "document_type": "page",
  "template_key": "default",
  "settings": {},
  "seo": {},
  "sections": []
}
```

**Document types:** `page`, `landing`, `system`, `header_partial`, `footer_partial`, `section_preset`.

**Route modes:** `standalone`, `homepage`, `system_override`.

**Settings keys:** `container_width`, `token_group`, `header_mode`, `footer_mode`, `category`, `tags`, `featured`, `excerpt`, `template_type`, `conditions`, `sticky_header`.

**Section keys:** `id`, `label`, `global_ref`, `layout`, `visibility`, `blocks` (required); `locked`, `style` (`background`, `padding`), `animation`, `interactions`.

**Section layout:** `columns`, `width`, `gap`, `padding_y`, `background_token`, `custom_width`. Widths: `default`, `narrow`, `normal`, `wide`, `full`, `custom`.

**Block keys:** `id`, `type`, `version`, `props`, `style`, `visibility`, `bindings`, `children` (required); `responsive`, `attributes`, `classNames`, `conditions`, `metadata`, `interactions`, `animation`, `style_states`, `style_parts`, `tag`, `link`.

**Ids:** `sec_` or `blk_` followed by 16 to 32 lowercase letters or digits.

**Tags:** `div`, `section`, `article`, `aside`, `header`, `footer`, `nav`, `figure`, `main`, `a`. Set `tag: "a"` with `link` to make a whole container a link.

**Attributes:** `id`, `role`, `tabindex`, `title`, `lang`, `aria-*`, `data-*`, except `data-sb-*`.

**Limits:**

| Limit | Value |
|---|---|
| Document size | 1 MB |
| Sections per document | 50 |
| Blocks per document | 250 |
| Nesting | 6 levels |
| Repeater items | 50 at most; some blocks fewer (`core.nav_menu` 12) |
| String / text / rich text | 2,000 / 20,000 / 50,000 characters |
| Custom CSS | 64 KB |
| Head or footer snippet | 32 KB |
| Redirects | 500 |
| Site menus | 15, each with 60 links |
| Package | 25 items |

**Application service:** `createPage($actor, $title, $slug, $pageType, $routeMode)`, `saveDraft($actor, $pageId, $document, $expectedRevisionId, 'manual', $summary)`, `publish($actor, $pageId, $revisionId, $summary)`, `saveDesignTokens($actor, 'default', $tokens)`, `saveTemplate(...)`, `archivePage($actor, $pageId)`.

---

## Appendix B: Style reference

Lengths can be px, rem, em, %, vw and similar, plus `calc()`, `min()`, `max()` and `clamp()` where a length is allowed. Colours can be literals or tokens.

### `style`

| Key | Fields |
|---|---|
| `align` | `{"base": "left"}`: left, center, right, justify |
| `typography` | `size`, `weight`, `line_height`, `letter_spacing`, `font_family`, `transform`, `color`, `style`, `decoration`, `decoration_style`, `decoration_color`, `decoration_thickness`, `decoration_offset`, `stroke_width`, `stroke_color` |
| `color` | Text colour |
| `background` | `color`, `gradient`, or `image` (`media_ref`) with `size`, `position`, `repeat`, `attachment` and `overlay`. Position follows the image's `focal_point`. Image and gradient can't be combined |
| `border` | `width`, `style`, `color`, `radius`; per side `top`, `right`, `bottom`, `left`; per corner `tl`, `tr`, `br`, `bl` |
| `shadow` | Preset (`none`, `sm`, `md`, `lg`, `xl`, `2xl`, `inner`), a string, or `{x, y, blur, spread, color, inset}` |
| `dimensions` | `width`, `height`, `min_height`, `max_width`, `min_width`, `max_height`, `aspect_ratio`, `overflow`, `object_fit`, `object_position` (keyword only) |
| `layout` | See below |
| `position` | `mode` (static, relative, absolute, sticky), `top`, `right`, `bottom`, `left` |
| `margin`, `padding` | `top`, `right`, `bottom`, `left` |
| `effects` | `transform` {`translate_x`, `translate_y`, `rotate`, `scale`, `skew_x`, `skew_y`}, `filter` {`blur`, `brightness`, `contrast`, `saturate`, `grayscale`}, `backdrop_blur`, `blend`, `transition` {`duration_ms`, `easing`}, `cursor` |
| `overlay` | `{color or gradient, opacity (0 to 1), blend}` on a container |
| `text_shadow` | `{x, y, blur, color}` |
| `opacity` | 0 to 1 on a block; **0 to 100 on a part** |
| `z_index` | -999 to 999 |

### `style.layout`

`display` (block, flex, grid, inline-block, inline-flex), `direction`, `wrap`, `justify` (start, center, end, between, around, evenly), `align` (start, center, end, stretch, baseline), `align_content`, `gap`, `row_gap`, `column_gap`, `order`, `grow`, `shrink`, `basis`, `align_self`, `columns` (1 to 12), `tracks`, `rows`, `column_span`, `row_span` (1 to 12 or `full`), `content_width` (boxed, full), `content_max`.

`tracks` takes up to 12 of `auto`, `min-content`, `max-content`, a number with `fr`, `px`, `rem`, `em`, `%` or `vw`, or `minmax(a,b)`.

### `responsive.<tablet|mobile>`

| Key | Takes |
|---|---|
| `style` | `typography.size`, `padding`, `margin`, `layout` (except `display`, `content_width`, `content_max`), `dimensions` (`width`, `height`, `min_height`, `max_width`) |
| `props` | Fields marked `~` in Appendix C, such as `columns` |
| `style_parts` | `typography.size`, `border.radius`, `padding`, `margin`, `size`, `layout` |
| `hide` | `true` hides on that device only |

Device overrides can't set colour, background, radius on a block, position or display on a block. Part `layout.display` can be set per device, including `none`.

### `style_states`

Keys `hover`, `focus`, `active`, `disabled`. Each takes `color`, `typography`, `background`, `border`, `shadow`, `opacity`, `effects`, `overlay`.

### `style_parts`

Keys are a part, alone or with a state: `part:hover`, `part:focus`, `part:not_first`. Pseudo-element parts such as markers take no state. Each value takes:

| Key | Fields |
|---|---|
| `color` | Text colour |
| `typography` | `family` (`heading` or `body`), `size`, `weight` (normal, medium, semibold, bold, extrabold), `line_height` (a length), `letter_spacing`, `transform`, `style`, `decoration` |
| `background` | `color`, `gradient` |
| `border` | `width`, `style`, `color`, `radius`, `sides` (all, top, right, bottom, left, top_bottom, left_right) |
| `padding`, `margin` | Per side |
| `shadow` | Object |
| `size` | `width`, `height`, `min_width`, `min_height`, `max_width`, `max_height` (these two accept `none`), `object_fit`, `overflow` |
| `layout` | `display` (including `none`), `direction`, `wrap`, `justify`, `align`, `gap`, `row_gap`, `column_gap`, `columns`, `tracks` |
| `position` | As on a block |
| `opacity` | 0 to 100 |
| `effects` | As on a block |

---

## Appendix C: Block catalogue

Generated from the code at `b53b949`. `*` marks a required field. `~` marks a field that can differ per device. Enums show their allowed values. Long icon lists are shortened to `<icon>`: check, check-circle, star, heart, arrow-right, mail, phone, map-pin, clock, calendar, user, users, shield, zap, globe, message, gift, award, camera, home, search, sparkle, play, leaf, dollar, menu, menu-short, close, facebook, twitter, x-social, instagram, youtube, linkedin, whatsapp, tiktok.

### Core

**`core.accordion`** (Accordion)

- Props: `items:repeater`, `allow_multiple:boolean`
- Parts: `item`, `title`, `icon`, `content`

**`core.button`** (Button)

- Props: `link:link*`, `variant:enum(primary \| secondary \| outline \| ghost)`, `size:enum(sm \| md \| lg)`, `full_width:boolean`, `icon:enum(none \| <icon>)`, `icon_position:enum(before \| after)`, `icon_spacing:number`
- Parts: `button`, `label`, `icon`

**`core.card`** (Card) — holds children

- Props: `variant:enum(outlined \| filled \| shadow)`, `padding:enum(sm \| md \| lg)`, `surface_token:token_ref`
- Parts: none (style the wrapper)

**`core.carousel`** (Carousel)

- Props: `slides:repeater`, `options:object`
- Parts: `quote`, `author`, `arrows`, `dots`

**`core.container`** (Container) — holds children

- Props: `gap:enum(none \| xs \| sm \| md \| lg \| xl \| 2xl)`, `direction:enum(vertical \| horizontal)`, `wrap:enum(wrap \| nowrap)`, `bg_video:url`, `shape_top:enum(none \| wave \| curve \| tilt \| triangle \| mountains)`, `shape_top_color:token_ref`, `shape_top_width:number`, `shape_top_height:number`, `shape_top_flip:boolean`, `shape_top_front:boolean`, `shape_bottom:enum(none \| wave \| curve \| tilt \| triangle \| mountains)`, `shape_bottom_color:token_ref`, `shape_bottom_width:number`, `shape_bottom_height:number`, `shape_bottom_flip:boolean`, `shape_bottom_front:boolean`
- Parts: none (style the wrapper)

**`core.countdown`** (Countdown)

- Props: `target:string*`, `label:string`, `done_text:string`
- Parts: `title`, `box`, `number`, `unit`

**`core.divider`** (Divider)

- Props: `style:enum(solid \| dashed \| dotted)`, `weight:enum(thin \| medium \| thick)`, `width:enum(full \| wide \| narrow \| short)`, `align:enum(left \| center \| right)`
- Parts: none (style the wrapper)

**`core.feature_list`** (Feature List)

- Props: `title:string`, `columns:number~`, `items:repeater`, `card_options:object`
- Parts: `title`, `card`, `heading`, `body`, `link`

**`core.form`** (Form) — holds children

- Props: `action:string`, `method:enum(post \| get)`, `form_name:string`, `submit_text:string`, `submit_variant:enum(primary \| secondary \| outline)`, `success_message:text`, `redirect_url:string`, `notify_email:string`, `labels:enum(visible \| hidden)`, `layout:enum(stacked \| inline)`
- Parts: `form`, `field`, `label`, `input`, `textarea`, `help`, `actions`, `button`

**`core.form_field`** (Form Field)

- Props: `name:string*`, `label:string*`, `field_type:enum(text \| email \| tel \| number \| url \| textarea \| select \| checkbox \| radio \| checkboxes \| date \| time \| file \| hidden)`, `placeholder:string`, `required:boolean`, `help_text:string`, `options:string`, `default_value:string`, `url_param:string`, `url_values:text`, `width:enum(100 \| 75 \| 66 \| 50 \| 33 \| 25)~`
- Parts: none (style the wrapper)

**`core.gallery`** (Gallery)

- Props: `columns:enum(2 \| 3 \| 4 \| 6)~`, `gap:enum(none \| xs \| sm \| md \| lg)`, `aspect_ratio:enum(auto \| 1:1 \| 4:3 \| 16:9)`, `rounded:boolean`, `images:repeater`
- Parts: `grid`, `item`, `image`

**`core.heading`** (Heading)

- Props: `text:string*`, `url:url`, `new_tab:boolean`, `level:enum(h1 \| h2 \| h3 \| h4 \| h5 \| h6 \| div \| span \| p)`, `highlight:string`, `highlight_color_token:token_ref`, `highlight_color:string`, `highlight_italic:boolean`, `highlight_weight:enum(inherit \| normal \| medium \| bold)`, `highlight_underline:boolean`
- Parts: `highlight`, `link`

**`core.hero`** (Hero Banner)

- Props: `eyebrow:string`, `heading:string*`, `highlight:string`, `highlight_color_token:token_ref`, `highlight_color:string`, `highlight_italic:boolean`, `highlight_weight:enum(inherit \| normal \| medium \| bold)`, `highlight_underline:boolean`, `subheading:text`, `primary_cta:link`, `media:media_ref`, `accent_token:token_ref`
- Parts: `eyebrow`, `heading`, `subheading`, `media`

**`core.icon`** (Icon)

- Props: `name:enum(<icon>)`, `view:enum(default \| stacked \| framed)`, `shape:enum(circle \| square)`, `url:url`, `new_tab:boolean`, `size:enum(sm \| md \| lg \| xl)`, `align:enum(left \| center \| right)`, `color_token:token_ref`, `label:string`, `secondary_token:token_ref`, `size_px:number`, `frame_padding:number`, `frame_border:number`, `rotate:number`
- Parts: `link`, `svg`

**`core.image`** (Image)

- Props: `media:media_ref*`, `caption:string`, `aspect_ratio:enum(auto \| 16:9 \| 4:3 \| 1:1 \| 3:4)`, `rounded:boolean`, `link_to:enum(none \| media \| custom)`, `url:url`, `new_tab:boolean`
- Parts: none (style the wrapper)

**`core.link`** (Link)

- Props: `link:link*`, `style:enum(underline \| plain \| arrow)`, `align:enum(left \| center \| right)`
- Parts: none (style the wrapper)

**`core.list`** (List)

- Props: `style:enum(bullet \| number \| check \| dash \| none)`, `layout:enum(stacked \| inline)`, `columns:number~`, `divider:boolean`, `items:repeater`
- Parts: `list`, `item`, `marker`

**`core.map`** (Map)

- Props: `latitude:number*`, `longitude:number*`, `zoom:number`, `height:enum(sm \| md \| lg)`, `title:string`
- Parts: `frame`

**`core.modal`** (Modal Popup) — holds children

- Props: `modal_id:string`, `title:string`, `size:enum(sm \| md \| lg \| full)`, `trigger_text:string`, `trigger_variant:enum(primary \| secondary \| outline \| ghost)`
- Parts: none (style the wrapper)

**`core.nav_menu`** (Nav menu)

- Props: `menu:string`, `aria_label:string`, `layout:enum(horizontal \| vertical)`, `align:enum(left \| center \| right)`, `style:enum(plain \| underline \| pill)`, `items:repeater`
- Parts: `list`, `item`, `link`, `current`, `submenu`, `sublink`, `toggle`

**`core.query_loop`** (Query Loop) — holds children

- Props: `source:enum(posts \| pages \| custom)`, `category:string`, `tag:string`, `author_id:number`, `order_by:enum(published_at \| title \| created_at)`, `order:enum(desc \| asc)`, `per_page:number`, `columns:number~`, `gap:enum(none \| xs \| sm \| md \| lg \| xl \| 2xl)`, `card_variant:enum(card \| minimal \| horizontal)`, `title_level:enum(h2 \| h3 \| h4 \| h5 \| h6)`, `show_featured_image:boolean`, `show_date:boolean`, `show_author:boolean`, `show_category:boolean`, `show_excerpt:boolean`, `read_more_text:string`, `enable_pagination:boolean`, `empty_message:string`
- Parts: `card`, `image`, `category`, `title`, `excerpt`, `meta`, `button`, `button_row`, `body`, `grid`

**`core.quote`** (Quote)

- Props: `text:text*`, `author:string`, `role:string`, `align:enum(left \| center)`
- Parts: `text`, `author`, `role`

**`core.rich_text`** (Rich Text)

- Props: `content:rich_text*`, `drop_cap:boolean`, `columns:number~`, `columns_gap:number`
- Parts: `paragraph`, `heading`, `list`, `item`, `marker`, `link`, `bold`

**`core.spacer`** (Spacer)

- Props: `size:enum(xs \| sm \| md \| lg \| xl \| 2xl)`
- Parts: none (style the wrapper)

**`core.stats`** (Stats)

- Props: `items:repeater`
- Parts: `number`, `label`

**`core.table`** (Table)

- Props: `caption:string`, `header:string`, `striped:boolean`, `rows:repeater`
- Parts: `header`, `cell`, `caption`

**`core.tabs`** (Tabs)

- Props: `items:repeater`, `options:object`
- Parts: `tab`, `tab_active`, `panel`

**`core.text`** (Text)

- Props: `content:text`, `size:enum(xs \| sm \| base \| lg \| xl \| lead \| inherit)`, `align:enum(left \| center \| right \| justify)`, `color_token:token_ref`, `drop_cap:boolean`, `columns:number~`, `columns_gap:number`
- Parts: none (style the wrapper)

**`core.video`** (Video)

- Props: `source:enum(youtube \| vimeo \| hosted)`, `url:string`, `hosted_url:url`, `start:number`, `end:number`, `aspect_ratio:enum(16:9 \| 4:3 \| 1:1 \| 9:16)`, `autoplay:boolean`, `muted:boolean`, `loop:boolean`, `controls:boolean`, `privacy:boolean`, `lazy_load:boolean`, `overlay:boolean`, `overlay_image:media_ref`, `play_icon:boolean`, `lightbox:boolean`
- Parts: `poster`, `play`, `svg`

### Layout

**`layout.container`** (Width container) — holds children

- Props: `width:enum(full \| constrained \| compact)`, `alignment:enum(left \| center \| right)`, `padding:enum(none \| xs \| sm \| md \| lg \| xl \| 2xl)`
- Parts: none (style the wrapper)

**`layout.flex`** (Flex) — holds children

- Props: `direction:enum(row \| column \| row_reverse \| column_reverse)`, `wrap:enum(nowrap \| wrap \| wrap_reverse)`, `justify:enum(start \| center \| end \| between \| around \| evenly)`, `align:enum(start \| center \| end \| stretch \| baseline)`, `gap:enum(none \| xs \| sm \| md \| lg \| xl \| 2xl)`
- Parts: none (style the wrapper)

**`layout.grid`** (Grid) — holds children

- Props: `columns:number~`, `gap:enum(none \| xs \| sm \| md \| lg \| xl \| 2xl)`, `align:enum(start \| center \| end \| stretch)`
- Parts: none (style the wrapper)

**`layout.offcanvas`** (Offcanvas Drawer) — holds children

- Props: `drawer_id:string`, `title:string`, `position:enum(left \| right \| top \| bottom)`, `trigger_text:string`, `trigger_variant:enum(primary \| secondary \| outline \| ghost \| plain)`, `trigger_icon:enum(none \| <icon>)`, `trigger_icon_size:number`, `trigger_show_text:boolean`
- Parts: `trigger`, `panel`, `header`, `close`, `body`, `backdrop`

**`layout.section`** (Section) — holds children

- Props: `tag:enum(section \| header \| footer \| article \| aside \| div)`, `content_width:enum(boxed \| full \| narrow)`, `min_height:enum(auto \| screen \| half_screen \| sm \| md \| lg \| xl)`, `padding_y:enum(none \| xs \| sm \| md \| lg \| xl \| 2xl)`, `background_token:token_ref`
- Parts: none (style the wrapper)

### Theme templates

**`theme.archive_title`** (Archive Title)

- Props: `level:enum(h1 \| h2 \| h3)`, `title:string`
- Parts: none (style the wrapper)

**`theme.field_value`** (Field value)

- Props: `field:string`, `show_label:boolean`, `fallback:string`, `tag:enum(div \| p \| span \| h2 \| h3 \| h4)`
- Parts: `value`, `label`, `paragraph`, `list`

**`theme.post_content`** (Post Content)

- Props: `content:text`
- Parts: none (style the wrapper)

**`theme.post_meta`** (Post Meta)

- Props: `show_author:boolean`, `show_date:boolean`, `show_category:boolean`, `separator:string`
- Parts: `meta`, `item`

**`theme.post_title`** (Post Title)

- Props: `level:enum(h1 \| h2 \| h3 \| h4 \| h5 \| h6)`, `align:enum(left \| center \| right)`, `text:string`
- Parts: none (style the wrapper)

**`theme.search_box`** (Search Box)

- Props: `placeholder:string`, `button_text:string`
- Parts: none (style the wrapper)

### Payment

**`payment.button`** (Payment Button)

- Props: `label:string`, `amount:number`, `amount_choice:enum(fixed \| visitor)`, `max_amount:number`, `frequency:enum(once \| month)`, `amount_label:string`, `currency:enum(USD \| EUR \| GBP \| CAD \| AUD \| CHF \| SEK \| NOK \| DKK \| PLN \| CZK \| NZD)`, `description:string`, `success_path:string`
- Parts: `button`, `form`, `amount`

**`payment.link`** (Stripe Payment Link)

- Props: `label:string`, `url:string`
- Parts: none (style the wrapper)

### Forms module

**`forms.embed`** (Form) — needs `forms`

- Props: none
- Parts: none (style the wrapper)

**`forms.form_card`** (Form Card) — needs `forms`

- Props: `button_label:string`, `show_description:boolean`
- Parts: none (style the wrapper)

### Shop module

**`shop.cart`** (Cart) — needs `shop`

- Props: none
- Parts: `table`, `title`, `options`, `totals`, `button`

**`shop.checkout`** (Checkout) — needs `shop`

- Props: none
- Parts: `fields`, `labels`, `summary`, `button`

**`shop.mini_cart`** (Mini Cart) — needs `shop`

- Props: `label:string`, `show_total:boolean`
- Parts: `link`, `count`, `total`

**`shop.order_confirmation`** (Order Confirmation) — needs `shop`

- Props: `heading:string`
- Parts: `heading`, `lead`, `total`

**`shop.product_add_to_cart`** (Add to Cart) — needs `shop`

- Props: `button_label:string`, `show_quantity:boolean`, `go_to_cart:boolean`
- Parts: `button`, `options`, `option_labels`, `variant_price`, `quantity`, `message`

**`shop.product_description`** (Product Description) — needs `shop`

- Props: `source:enum(long \| short)`
- Parts: none (style the wrapper)

**`shop.product_image`** (Product Images) — needs `shop`

- Props: `ratio:enum(square \| portrait \| landscape \| natural)`, `show_thumbnails:boolean`
- Parts: `main`, `thumbs`

**`shop.product_meta`** (Product Meta) — needs `shop`

- Props: `show_sku:boolean`, `show_categories:boolean`
- Parts: `label`, `value`

**`shop.product_price`** (Product Price) — needs `shop`

- Props: `show_vat_note:boolean`
- Parts: `regular`, `sale`, `vat`

**`shop.product_stock`** (Product Stock) — needs `shop`

- Props: `low_stock:number`, `show_in_stock:boolean`
- Parts: `in`, `low`, `out`

**`shop.product_title`** (Product Title) — needs `shop`

- Props: `tag:enum(h1 \| h2 \| h3 \| h4)`
- Parts: none (style the wrapper)

**`shop.products`** (Product Grid) — needs `shop`

- Props: `heading:string`, `columns:number`, `order:enum(menu \| newest \| price_asc \| price_desc \| title)`, `per_page:number`, `show_filter:boolean`, `show_pager:boolean`, `show_price:boolean`, `show_add_to_cart:boolean`, `button_label:string`, `link_label:string`, `options_label:string`, `show_icon:boolean`, `icon:enum(<icon>)`, `empty_message:string`
- Parts: `card`, `image`, `body`, `icon`, `link`, `title`, `price`, `button`, `badge`, `filter`, `pager`, `grid`

### Booking module

**`booking.embed`** (Booking) — needs `booking`

- Props: `min_height:number`
- Parts: none (style the wrapper)

**`booking.service`** (Service Card) — needs `booking`

- Props: `show_price:boolean`, `show_duration:boolean`, `cta_label:string`
- Parts: none (style the wrapper)

**`booking.services`** (Booking Services) — needs `booking`

- Props: `heading:string`, `show_prices:boolean`, `cta_label:string`
- Parts: none (style the wrapper)

### Membership module

**`membership.member_links`** (Member Sign-in) — needs `membership`

- Props: `signin_label:string`, `show_join:boolean`, `join_label:string`, `show_account:boolean`, `account_label:string`
- Parts: none (style the wrapper)

**`membership.plan`** (Plan Card) — needs `membership`

- Props: `show_price:boolean`, `cta_label:string`
- Parts: none (style the wrapper)

**`membership.plans`** (Membership Plans) — needs `membership`

- Props: `heading:string`, `show_prices:boolean`, `cta_label:string`
- Parts: none (style the wrapper)

---

## Appendix D: Reference installer

A small, complete, tested installer: theme, media, content type, header, footer, home, list page, one item, a single template and a redirect. Copy it, then replace the content. Keep `B()`, `SEC()`, `MID()` and `page()`, and add helpers for your components.

For a full production example, see `01-client/sites/fightxlife/build/`: a real site with 73 pages, a class-to-native style map in `native.php` and a design-system helper layer.

Put images in `media/` beside the script. This example expects `media/hero.png`.

```php
<?php
/**
 * Reference installer for a converted site. Run from the Kohevo site root:
 *     cd <site root> && php <kit>/install.php
 * Idempotent: pages are matched by slug and page type, media by path, settings are overwritten.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
if (!is_file(getcwd() . '/config.php')) { fwrite(STDERR, "Run from the site root.\n"); exit(1); }
require_once getcwd() . '/config.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema as Schema;
use Slate\Module\StudioBuilder\Document\ValidatedDocument;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Meta\PageRoutes;
use Slate\Module\StudioBuilder\Runtime\StudioRedirects;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Media\Media;

$tid = current_tenant_id();
PluginLoader::activePlugins();
$runtime = StudioRuntimeFactory::build();
$adminId = (int) Database::value(
    "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
     WHERE u.tenant_id = ? AND u.status = 'active' ORDER BY r.is_system DESC, u.id ASC LIMIT 1", [$tid]);
$actor = StudioActor::authenticated($adminId, StudioPermissions::ALL, true);
$defs = [];
foreach ($runtime->registry->editorManifests() as $m) { $defs[$m['type']] = $m; }

/** A block with its manifest defaults. $opt: style, responsive, style_states, style_parts, animation, interactions, attributes, id. */
function B(string $type, array $props = [], array $children = [], array $opt = []): array
{
    global $defs;
    if (!isset($defs[$type])) { throw new RuntimeException("No block type {$type} on this install"); }
    $b = [
        'id' => $opt['id'] ?? Schema::newBlockId(), 'type' => $type, 'version' => (int) $defs[$type]['version'],
        'props' => array_replace((array) ($defs[$type]['default_props'] ?? []), $props),
        'style' => $opt['style'] ?? ['align' => ['base' => 'left']],
        'visibility' => Schema::defaultVisibility(), 'bindings' => [], 'children' => $children,
    ];
    foreach (['responsive', 'style_states', 'style_parts', 'animation', 'interactions', 'attributes', 'tag', 'link'] as $k) {
        if (isset($opt[$k])) { $b[$k] = $opt[$k]; }
    }
    return $b;
}

/** A full-width section holding one boxed container (the site's content width, with side padding per device). */
function SEC(string $label, array $blocks, array $style = [], array $responsive = [], array $opt = []): array
{
    $style = array_replace_recursive(['align' => ['base' => 'left'], 'layout' => ['content_width' => 'boxed']], $style);
    $gutters = ['tablet' => ['style' => ['padding' => ['left' => '24px', 'right' => '24px']]], 'mobile' => ['style' => ['padding' => ['left' => '18px', 'right' => '18px']]]];
    return [
        'id' => Schema::newSectionId(), 'label' => $label, 'global_ref' => null,
        'layout' => ['background_token' => 'surface.primary', 'columns' => ['base' => 1, 'md' => 1], 'gap' => 'none', 'padding_y' => ['base' => 'none'], 'width' => 'full'],
        'visibility' => Schema::defaultVisibility(),
        'blocks' => [B('layout.container', ['width' => 'full', 'padding' => 'none'], $blocks, ['style' => $style, 'responsive' => array_replace_recursive($gutters, $responsive)] + $opt)],
    ];
}

function MID(string $path): int
{
    global $tid;
    return (int) Database::value('SELECT id FROM media_files WHERE tenant_id = ? AND path = ?', [$tid, $path]);
}

function page(string $slug, string $title, string $type, string $route, array $sections, array $settings = [], array $seo = []): int
{
    global $runtime, $actor, $tid;
    $doc = Schema::emptyDocument($type, 'default', $seo['title'] ?? $title);
    $doc['sections'] = $sections;
    $doc['settings'] = array_replace($doc['settings'], $settings);
    foreach (['description', 'canonical_url', 'robots', 'og_image_media_id'] as $k) {
        if (isset($seo[$k])) { $doc['seo'][$k] = $seo[$k]; }
    }
    try {
        ValidatedDocument::from($doc, $runtime->registry, ['allow_locked' => true]);
    } catch (StudioValidationException $e) {
        foreach ($e->errors() as $i) { echo "  ! {$slug} {$i['path']} {$i['code']}: {$i['message']}\n"; }
        throw new RuntimeException("Invalid document: {$slug}");
    }
    $old = Database::row("SELECT id, active_draft_revision_id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = ? AND page_type = ? AND status <> 'archived'", [$tid, $slug, $type]);
    if ($old) {
        $id = (int) $old['id'];
        $head = (int) $old['active_draft_revision_id'];
    } else {
        $created = $runtime->app->createPage($actor, $title, $slug, $type, $route);
        $id = (int) $created['page']['id'];
        $head = (int) $created['revision']['id'];
    }
    $draft = $runtime->app->saveDraft($actor, $id, $doc, $head, 'manual', 'Converted from Next.js');
    $runtime->app->publish($actor, $id, (int) $draft['revision']['id'], 'Converted from Next.js');
    echo "  + {$type} /{$slug} (#{$id})\n";
    return $id;
}

// ── 1. Media ─────────────────────────────────────────────────────────────────────────────────────────────────────
$src = __DIR__ . '/media';
$dst = getcwd() . '/uploads/media/site';
if (!is_dir($dst) && !mkdir($dst, 0755, true) && !is_dir($dst)) { throw new RuntimeException('uploads is not writable'); }
foreach (glob($src . '/*') ?: [] as $file) {
    $name = basename($file);
    if (!is_file("{$dst}/{$name}") || filesize("{$dst}/{$name}") !== filesize($file)) { copy($file, "{$dst}/{$name}"); }
    $info = @getimagesize("{$dst}/{$name}") ?: [null, null, 'mime' => (string) mime_content_type("{$dst}/{$name}")];
    Media::register("/uploads/media/site/{$name}", ['mime' => (string) $info['mime'], 'size_bytes' => filesize("{$dst}/{$name}"), 'width' => $info[0], 'height' => $info[1], 'original_name' => $name]);
}

// ── 2. Theme and site settings ───────────────────────────────────────────────────────────────────────────────────
$runtime->app->saveDesignTokens($actor, 'default', [
    'surface.page' => '#ffffff', 'surface.primary' => '#ffffff', 'surface.secondary' => '#f4f4f5', 'surface.accent' => '#2563eb', 'surface.inverse' => '#0f172a',
    'text.primary' => '#0f172a', 'text.body' => '#475569', 'text.heading' => '#0f172a', 'text.muted' => '#64748b', 'text.accent' => '#2563eb', 'text.inverse' => '#ffffff',
    'color.accent' => '#2563eb', 'border.default' => '#e2e8f0', 'font.heading' => 'Inter, Arial, sans-serif', 'font.body' => 'Inter, Arial, sans-serif',
    'radius.md' => '12px', 'layout.content' => '1200px', 'type.body_size' => '16px', 'type.body_leading' => '1.6', 'type.heading_leading' => '1.15',
    'type.flow' => '0', 'type.heading_gap' => '0', 'type.section_gutter' => '0',
]);
$fonts = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
       . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">';
Database::setSetting(StudioCodePolicy::SETTING_HEAD_SNIPPET, StudioCodePolicy::prepareSnippet($fonts, 'head'), $tid);
Database::setSetting(StudioCodePolicy::SETTING_CUSTOM_CSS, '', $tid);
Database::setSetting('site_name', 'Acme', $tid);
Database::setSetting(PageRoutes::SETTING_TRAILING_SLASH, '0', $tid);

// ── 3. Content types (one Next.js dynamic route = one content type) ──────────────────────────────────────────────
$types = PageMeta::postTypes($tid);
$types['service'] = ['key' => 'service', 'singular' => 'Service', 'plural' => 'Services', 'route' => '/services', 'is_system' => false, 'description' => 'One page per /services/[slug].'];
Database::setSetting('studio_custom_post_types', json_encode($types, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tid);
$fields = [['id' => 'f_' . substr(md5('service.price'), 0, 8), 'post_type' => 'service', 'label' => 'Price', 'key' => 'price', 'type' => 'text', 'required' => false, 'placeholder' => '', 'help' => '', 'options' => []]];
Database::setSetting('studio_meta_fields', json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tid);
PageRoutes::forget($tid);

// ── 4. Header and footer ─────────────────────────────────────────────────────────────────────────────────────────
$nav = [['label' => 'Home', 'url' => '/'], ['label' => 'Services', 'url' => '/services']];
page('default', 'Header', 'header_partial', 'standalone', [SEC('Header', [
    B('layout.flex', ['direction' => 'row', 'justify' => 'between', 'align' => 'center', 'gap' => 'none'], [
        B('core.heading', ['text' => 'Acme', 'level' => 'div', 'url' => '/'], [], ['style' => ['align' => ['base' => 'left'], 'color' => '#0f172a', 'typography' => ['size' => '20px', 'weight' => 'bold']]]),
        B('core.nav_menu', ['items' => $nav, 'layout' => 'horizontal', 'aria_label' => 'Main'], [], ['style_parts' => ['link' => ['color' => '#0f172a'], 'link:hover' => ['color' => '#2563eb'], 'current' => ['color' => '#2563eb']]]),
    ]),
], ['padding' => ['top' => '16px', 'bottom' => '16px']])], ['sticky_header' => ['edge' => 'top', 'shadow' => true]]);
page('default', 'Footer', 'footer_partial', 'standalone', [SEC('Footer', [
    B('core.text', ['content' => '© Acme', 'size' => 'inherit']),
], ['padding' => ['top' => '32px', 'bottom' => '32px'], 'background' => ['color' => '#0f172a'], 'color' => '#ffffff'])]);

// ── 5. Pages ─────────────────────────────────────────────────────────────────────────────────────────────────────
$card = static fn(string $title, string $text): array => B('core.container', ['direction' => 'vertical', 'gap' => 'none'], [
    B('core.heading', ['text' => $title, 'level' => 'h3'], [], ['style' => ['align' => ['base' => 'left'], 'typography' => ['size' => '20px'], 'margin' => ['bottom' => '8px']]]),
    B('core.text', ['content' => $text, 'size' => 'inherit']),
], ['style' => [
    'align' => ['base' => 'left'], 'padding' => ['top' => '24px', 'right' => '24px', 'bottom' => '24px', 'left' => '24px'], 'background' => ['color' => '#ffffff'],
    'border' => ['radius' => '12px'], 'shadow' => ['x' => '0px', 'y' => '8px', 'blur' => '24px', 'spread' => '0px', 'color' => 'rgba(15,23,42,.08)'],
    'effects' => ['transition' => ['duration_ms' => 200, 'easing' => 'ease']],
], 'style_states' => ['hover' => ['effects' => ['transform' => ['translate_y' => '-4px']]]]]);

$heroImage = MID('/uploads/media/site/hero.png');
page('home', 'Home', 'page', 'homepage', [
    SEC('Hero', [
        B('core.heading', ['text' => 'Build faster with Acme', 'level' => 'h1', 'highlight' => 'Acme'], [], ['style' => [
            'align' => ['base' => 'center'], 'typography' => ['size' => 'clamp(36px,5vw,60px)', 'weight' => 'bold'], 'margin' => ['bottom' => '16px']],
            'responsive' => ['mobile' => ['style' => ['typography' => ['size' => '34px']]]]]),
        B('core.text', ['content' => 'A short description from the Next.js page.', 'size' => 'inherit'], [], ['style' => ['align' => ['base' => 'center'], 'margin' => ['bottom' => '24px']]]),
        B('layout.flex', ['direction' => 'row', 'justify' => 'center', 'align' => 'center', 'gap' => 'none', 'wrap' => 'wrap'], [
            B('core.button', ['link' => ['label' => 'Our services', 'href' => '/services', 'target' => '_self'], 'variant' => 'primary'], [], ['style_parts' => [
                'button' => ['border' => ['width' => '0px', 'radius' => '999px'], 'size' => ['height' => '52px'], 'padding' => ['left' => '28px', 'right' => '28px'], 'layout' => ['display' => 'inline-flex', 'align' => 'center']],
                'button:hover' => ['effects' => ['transform' => ['translate_y' => '-2px']]]]]),
        ], ['style' => ['align' => ['base' => 'left'], 'layout' => ['gap' => '12px'], 'margin' => ['bottom' => '40px']]]),
        B('core.image', ['media' => ['media_id' => $heroImage, 'alt' => 'Product screenshot', 'focal_point' => [0.5, 0.5]], 'rounded' => false], [], ['style' => [
            'align' => ['base' => 'left'], 'dimensions' => ['height' => '420px', 'object_fit' => 'cover', 'overflow' => 'hidden'], 'border' => ['radius' => '16px']],
            'responsive' => ['mobile' => ['style' => ['dimensions' => ['height' => '240px']]]]]),
    ], ['padding' => ['top' => '96px', 'bottom' => '96px'], 'background' => ['color' => '#f4f4f5']], ['mobile' => ['style' => ['padding' => ['top' => '56px', 'bottom' => '56px']]]]),
    SEC('Features', [
        B('layout.grid', ['columns' => 3, 'gap' => 'none'], [$card('Fast', 'First feature.'), $card('Native', 'Second feature.'), $card('Editable', 'Third feature.')], ['style' => [
            'align' => ['base' => 'left'], 'layout' => ['gap' => '24px']], 'responsive' => ['tablet' => ['props' => ['columns' => 2]], 'mobile' => ['props' => ['columns' => 1]]]]),
    ], ['padding' => ['top' => '80px', 'bottom' => '80px']], [], ['animation' => ['type' => 'fade_up']]),
], [], ['title' => 'Acme | Build faster', 'description' => 'The home page description from generateMetadata.', 'og_image_media_id' => $heroImage]);

page('services', 'Services', 'page', 'standalone', [SEC('Services', [
    B('core.heading', ['text' => 'Services', 'level' => 'h1'], [], ['style' => ['align' => ['base' => 'left'], 'typography' => ['size' => '44px'], 'margin' => ['bottom' => '24px']]]),
    B('core.query_loop', ['source' => 'posts', 'category' => 'Services', 'columns' => 3, 'show_author' => false, 'show_date' => false, 'show_category' => false, 'read_more_text' => 'View']),
], ['padding' => ['top' => '80px', 'bottom' => '80px']])], [], ['description' => 'All services.']);

$id = page('web-design', 'Web design', 'page', 'standalone', [SEC('Body', [
    B('core.rich_text', ['content' => '<p>Body of the <strong>service</strong>.</p>']),
], ['layout' => ['content_width' => 'full']])], ['category' => 'Services', 'excerpt' => 'One service.'], ['description' => 'One service.']);
PageMeta::save($tid, $id, 'service', ['price' => '€900']);

// ── 6. Single template for the content type ──────────────────────────────────────────────────────────────────────
$rules = [['type' => 'include', 'condition' => 'post_type', 'value' => 'service']];
$single = [SEC('Service', [
    B('theme.post_title', ['level' => 'h1']),
    B('theme.field_value', ['field' => 'price', 'show_label' => true]),
    B('theme.post_content'),
], ['padding' => ['top' => '80px', 'bottom' => '80px']])];
$doc = Schema::emptyDocument('system', 'default', 'Service');
$doc['seo']['robots'] = 'noindex,nofollow';
$doc['settings']['conditions'] = ['rules' => $rules];
$doc['settings']['template_type'] = 'single';
$doc['sections'] = $single;
$runtime->app->saveTemplate($actor, 'service-single', 'page_template', 'single', 'Service', 'Single service', $doc, null, true);
page('template-service-single', '[Theme] Service', 'system', 'standalone', $single, ['conditions' => ['rules' => $rules], 'template_type' => 'single'], ['title' => 'Service', 'robots' => 'noindex,nofollow']);

// ── 7. Redirects (next.config.js redirects()) ────────────────────────────────────────────────────────────────────
Database::setSetting(StudioRedirects::SETTING, json_encode([['from' => '/old-services', 'to' => '/services', 'code' => 301]], JSON_UNESCAPED_SLASHES), $tid);
echo "done\n";
```

---

## Appendix E: Verifier

Tested with Playwright. `routes.txt` has one path per line. It screenshots each route at each width into `shots/` and exits with 1 if anything fails.

```js
// Usage: PW=<path to playwright> node verify.cjs <base URL ending in /> routes.txt [widths]
// Checks every route at every width. Exit code 1 when anything fails.
const { chromium } = require(process.env.PW || 'playwright');
const fs = require('fs');
const base = process.argv[2];
const routes = fs.readFileSync(process.argv[3], 'utf8').split('\n').map((r) => r.trim().replace(/^\//, '')).filter((r, i, a) => a.indexOf(r) === i);
const widths = (process.argv[4] || '360,390,480,600,768,900,1024,1280,1440').split(',').map(Number);
(async () => {
  const browser = await chromium.launch();
  const fails = [];
  for (const width of widths) {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await context.newPage();
    let at = '';
    page.on('console', (m) => { if (m.type() === 'error') fails.push(`${width} /${at} console: ${m.text().slice(0, 120)}`); });
    page.on('pageerror', (e) => fails.push(`${width} /${at} script error: ${e.message.slice(0, 120)}`));
    page.on('response', (r) => { if (r.status() >= 400 && r.url().startsWith(base)) fails.push(`${width} /${at} ${r.status()}: ${r.url()}`); });
    for (const route of routes) {
      at = route;
      const res = await page.goto(base + route, { waitUntil: 'load' });
      if (!res || res.status() !== 200) { fails.push(`${width} /${route}: HTTP ${res && res.status()}`); continue; }
      await page.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 500) { scrollTo(0, y); await new Promise((r) => setTimeout(r, 40)); } scrollTo(0, 0); });
      const issues = await page.evaluate((W) => {
        const out = [];
        if (document.documentElement.scrollWidth > W + 1) out.push(`page scrolls sideways: ${document.documentElement.scrollWidth}px`);
        if (document.querySelectorAll('h1').length !== 1) out.push(`${document.querySelectorAll('h1').length} h1 elements`);
        if (!document.title) out.push('no title');
        if (!document.querySelector('meta[name="description"]')) out.push('no meta description');
        for (const img of document.images) {
          if (img.closest('.sb-lightbox')) continue;
          if (img.complete && !img.naturalWidth) out.push(`broken image ${img.src.slice(-60)}`);
          if (!img.hasAttribute('alt')) out.push(`image without alt ${img.src.slice(-60)}`);
        }
        for (const h of document.querySelectorAll('.sb-heading')) if (h.scrollWidth > h.clientWidth + 2) out.push(`heading overflows: ${h.textContent.trim().slice(0, 30)}`);
        return out;
      }, width);
      for (const i of issues) fails.push(`${width} /${route}: ${i}`);
      fs.mkdirSync('shots', { recursive: true });
      await page.screenshot({ path: `shots/${(route.replace(/\W+/g, '_') || 'home')}-${width}.png`, fullPage: true });
    }
    await context.close();
  }
  await browser.close();
  const unique = [...new Set(fails)];
  console.log(unique.length ? unique.join('\n') : `ok: ${routes.length} routes at ${widths.length} widths`);
  process.exit(unique.length ? 1 : 0);
})();
```

---

## Appendix F: Conversion report template

```markdown
# Conversion report: <site>

## Summary
- Source: <repo, commit, Next.js version, router>
- Target: <Kohevo version, URL>
- Status: <ready / blocked, and why>

## Numbers
| Check | Result |
|---|---|
| Routes converted / total | |
| Redirects | |
| Blocks / pages | |
| Custom CSS bytes / !important | |
| Custom widgets | |
| Verifier (routes × widths) | |
| Text differences | |
| Metadata differences | |

## Decisions the owner made
## URL changes
| Old | New | Why |

## Differences from the original
| Page or component | Difference | Reason | Owner accepted? |

## Gaps
| Item | Fix used | Size | What Studio would need |

## Not converted
| Item | Why | Where it went |

## Not tested
## Owner actions
API keys, webhooks, DNS, email, content review.

## Rollback
```
