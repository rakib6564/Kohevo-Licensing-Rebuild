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
- **The site is dynamic: repeated content lives in content types, drawn by templates and listed by Query Loops.**
  - **Project** (`/case-study/<slug>/`, `build/project.php`): industry, heading, summary, client, country, year,
    service, stack (one per line), live site, desktop and phone screenshots, the four chapters, weeks / PageSpeed /
    pages, the client's quote, a short line and the industry colour. *Single: Project* draws every case study from
    these (browser-framed hero, stack ticker, facts, chapter list that follows the reader, screens, result numbers,
    quote, call to action, next project); a section whose field is empty is left out. Work and Construction list
    them with Query Loops (one page each, no popup; industry chips filter in place); the home page's six are the
    *featured* items, shown with their own card (*Loop Item: Project (home)*).
  - **Service** (`/wordpress-websites/` …, `build/services.php`): heading, intro, buttons, card text, best-for line,
    group, and the page's sections as fields: highlights, features, statement, checklist, steps, pricing and
    questions (repeaters), and the closing call to action. *Single: Service* draws them in a fixed order; the
    Services hub lists the items by group (category) with *Loop Item: Service*.
  - **Review** (no pages): 101 Fiverr reviews. The Reviews page shows the 66 longer ones with the **Testimonial
    grid** (6/4/3 per page, "read full review" dialog) and the short ones scrolling as its ticker layout.
  - **FAQ** (no pages): one item per question, the topic as its category; the FAQ page lists each topic with a
    Query Loop drawn as accordion rows (*Loop Item: FAQ*).
- Full-screen menu: the off-canvas widget's full-screen position with the Nav menu's numbers, captions and preview
  pictures (set on each item of the "Full menu" on the Menus screen).
- Phones: headings keep proportional tracking (per-device letter spacing), stacked rows line up left, the header
  keeps the logo and an icon-only menu button, and the footer's link columns sit two across.

## Differences from the original

- Hero: a radial gradient and a pointer glow instead of the WebGL smoke shader.
- Hero rotating word: one colour (periwinkle); the original gives each word its industry's colour.
- Worldwide: the dotted client map is not rebuilt; the countries are listed as chips beside the live Dhaka time.
- Header: the Industries and Services dropdowns are plain sub-menus, not the original's mega panel with a featured
  case study.
- In-page links (`#bd-audit`) go to the Free audit page instead.
- Case studies are one page each instead of one page with a picker.
- Service pages show their sections in one fixed order (highlights, features, statement, checklist, steps, pricing,
  questions); a few sections of the original are folded into features rows (the platform picker, a second checklist)
  and the Kohevo products strip on Custom development is not shown.
- Home: the six featured projects follow the list order (newest first), not the original's hand-picked order.

## Placeholder content to replace before launch

The source README calls these generated filler: the review quotes and names, the "5.0 / 101 reviews" and
"250+ / 14 countries" figures, the per-industry site counts, the per-country counts, the audit checklists in the
Three rules panels (42 / 42 pages, 118 / 118 redirects, "+3 enquiries"), and "Available from December 2026".
