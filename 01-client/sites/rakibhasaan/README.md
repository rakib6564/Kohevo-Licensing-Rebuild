# rakibhasaan.com in Kohevo Studio

A rebuild of the "Built Different" static site (github.com/rakib6564/rakibhasaan-site) with native Studio blocks.

- `build/site.php` builds the theme, menus, header, footer and pages; run it from the site root:
  `php <bundle>/build/site.php`. `RK_ONLY=home` builds the theme, header and footer and just those pages.
  It replaces the pages it builds.
- `build/lib.php` holds the helpers and the palette (ink, bone, navy, orange; Geist and Geist Mono).
- `build/site.css` is the site's custom CSS: the little the block styles cannot say.
- `media/` (gitignored) holds the original's images from `site/dist/assets/img/`, under the same names.

## Done

Home page, header and footer.

## Differences from the original

- Hero: a radial gradient and a pointer glow instead of the WebGL smoke shader.
- Hero rotating word: one colour (periwinkle); the original gives each word its industry's colour.
- Worldwide: the dotted client map is not rebuilt; the countries are listed as chips beside the live Dhaka time.
- Header: the Industries and Services dropdowns are plain sub-menus, not the original's mega panel with a featured
  case study; the full-screen menu is a side drawer.
- In-page links (`#bd-audit`) go to the Free audit page instead.

## Placeholder content to replace before launch

The source README calls these generated filler: the review quotes and names, the "5.0 / 101 reviews" and
"250+ / 14 countries" figures, the per-industry site counts, the per-country counts, the audit checklists in the
Three rules panels (42 / 42 pages, 118 / 118 redirects, "+3 enquiries"), and "Available from December 2026".
