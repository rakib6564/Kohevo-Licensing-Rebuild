# Faisal Hossen portfolio, built with Kohevo Studio

A complete rebuild of [mefahim/Faisal-Hossen-Portfolio](https://github.com/mefahim/Faisal-Hossen-Portfolio) as **Kohevo Studio blocks**:
every heading, paragraph, button, image, list, tab set and form on the seven public pages is an ordinary, editable
block in the builder. Nothing is a pasted HTML island.

| Reference route | Studio page (slug) |
| --- | --- |
| `/` | `home` (homepage) |
| `/work` | `work` |
| `/work/peoria-hardwood-floors` | `work-peoria-hardwood-floors` |
| `/work/nicola` | `work-nicola` |
| `/work/ai-flooring-visualizer` | `work-ai-flooring-visualizer` |
| `/about` | `about` |
| `/contact` | `contact` |
| header / footer | the site-wide `default` header and footer partials |

## How it is put together

- `src/content.mjs` — the copy, verbatim from the reference (`content/site.ts`, `content/projects.ts`).
- `src/dsl.mjs` — a tiny helper that writes canonical Studio documents (sections → blocks) with stable ids.
- `src/pages.mjs` — one function per page. Each block carries a CSS class (Advanced → CSS classes in the builder).
- `src/css/*.css` — the reference design ported onto the builder's markup: tokens, type, buttons, icons (CSS masks),
  every section, and the 800 px / 480 px breakpoints. Installed as the site's **Custom CSS**.
- `tools/install.mjs` — creates or updates everything through the builder's own authoring API
  (`save_tokens`, `save_custom_css`, `create_page`, `save_draft`, `publish`) and uploads the six images.

Design tokens are set to the reference palette (`#f5f3ee` page, `#11130f` ink, `#b6ff45` accent) and the fonts
(Inter + DM Sans) are loaded with a `<link>` in **Code & tracking → header snippet**.

## Install

```bash
npm install
# 1. Local sandbox (see 01-client/plugins/studio-builder/ui/e2e/sandbox.sh), served with big uploads allowed:
#    php -d upload_max_filesize=64M -d post_max_size=128M -S localhost:8200 -t <sandbox>/site <sandbox>/site/dev-server.php
FH_ASSETS=/path/to/Faisal-Hossen-Portfolio/public/assets node tools/install.mjs

# 2. A real site: a browser window opens and YOU sign in; the script never sees a password.
FH_BASE=https://example.com/site FH_ASSETS=/path/to/Faisal-Hossen-Portfolio/public/assets node tools/install.mjs
```

Re-running updates the existing pages in place (new draft revision, then publish). `FH_MEDIA='{"workbench":1,...}'`
reuses images that are already uploaded; `node tools/install.mjs home work` limits it to those slugs.

## Checking it against the reference

`tools/shoot.mjs <base-url> <out-dir>` takes full-page screenshots (desktop 1440 and mobile 390),
`tools/side-by-side.cjs` joins two screenshots for comparison and `tools/measure.mjs <url>` prints the position of every
heading, eyebrow and button, which is how the two sites were matched to within a few pixels.

## Where Studio differs from the reference (on purpose)

- **URLs.** Studio page slugs are flat, so case studies live at `/work-nicola`, not `/work/nicola`. Add redirects if the
  old paths matter.
- **Faisal's Room** (the private owner control centre, MySQL content store, login) is not rebuilt: Studio's builder is the
  editor for this site.
- **Contact form.** It is Studio's form block. The reference posts to its own `/api/contact` with an inline status message
  and a honeypot; delivery here is whatever the site's form handling provides.
- **"Powered by Kohevo"** is the platform signature Studio always renders on a site without a white-label licence.
- **Problem playground.** The three-state widget is a Studio *Tabs* block; its panel text is styled with `::first-line`
  rather than a bespoke component.
- **Scroll reveal** uses the builder's `fade_up` entrance animation on the main groups.
- **Social icons and arrows** are CSS masks, so the blocks stay plain, editable text.
