# rakibhasaan.com in Kohevo Studio

A rebuild of the "Built Different" static site (github.com/rakib6564/rakibhasaan-site) with native Studio blocks.

- `build/site.php` builds the theme, menus, header, footer and pages; run it from the site root:
  `php <bundle>/build/site.php`. `RK_ONLY=home` builds the theme, header and footer and just those pages.
  It replaces the pages it builds.
- `build/lib.php` holds the helpers and the palette (ink, bone, navy, orange; Geist and Geist Mono).
- `build/site.css` is the site's custom CSS: the little the block styles cannot say.
- `media/` (gitignored) holds the original's images from `site/dist/assets/img/`, under the same names.

## Done

Every page: home, header, footer, Work, Services and its six service pages, Construction, Process, About, Reviews,
FAQ, Contact, Free audit (at `/free-audit/`: `/audit` is reserved by the platform), and the 25 case studies.

- `build/export.py <rakibhasaan-site checkout>` rewrites `data/pages.json` from the original's `copy.md` with the
  original generator's own parser; run it when the copy changes, then the build.
- Case studies are a **Project** content type (`/case-study/<slug>/`): an Industry taxonomy, fields for each part,
  a Single template with a "Next project" link (dynamic tags) and a card template. The Work page is a Query Loop with
  industry chips and items opening in a dialog.

## Differences from the original

- Hero: a radial gradient and a pointer glow instead of the WebGL smoke shader.
- Hero rotating word: one colour (periwinkle); the original gives each word its industry's colour.
- Worldwide: the dotted client map is not rebuilt; the countries are listed as chips beside the live Dhaka time.
- Header: the Industries and Services dropdowns are plain sub-menus, not the original's mega panel with a featured
  case study; the full-screen menu is a side drawer.
- In-page links (`#bd-audit`) go to the Free audit page instead.
- Work: 24 projects per page (the Query Loop's limit), so the 25th is on page 2 and the chips reload the page.
- Case studies are one page each instead of one page with a picker.

## Placeholder content to replace before launch

The source README calls these generated filler: the review quotes and names, the "5.0 / 101 reviews" and
"250+ / 14 countries" figures, the per-industry site counts, the per-country counts, the audit checklists in the
Three rules panels (42 / 42 pages, 118 / 118 redirects, "+3 enquiries"), and "Available from December 2026".
