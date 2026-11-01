# Chef Gregory's in Kohevo Studio

A rebuild of chefgregory.com in Studio, from the public site only.

- `build/site.php` builds everything; run it from the site root: `php <bundle>/build/site.php`. It replaces the pages it builds.
- `build/lib.php` turns a capture of the live page structure into native Studio blocks. `build/thanksgiving.php` is the one hand-built page.
- `build/gap.css` holds the little that native style cannot say yet.
- `data/` (capture and WordPress REST data) and `media/` are gitignored.

Content types: Services, Blog Posts, Recipes and Catering Areas, each with meta fields and one Single template.

Addresses differ from the live site in two places, both with a 301: `/catering-in-*` and `/paso-robles-wedding-catering/` move under `/catering/`, and `/contact/help-us-help-you/` moves to `/help-us-help-you/`.

## Redesign

`build/design.php` and `build/design.css` hold the new theme, header, footer and home page; `services.php`, `about.php`, `areas.php`, `food.php`, `blog.php` and `more.php` hold the other redesigned pages; `site.php` runs them last, and `php <bundle>/build/design.php` redoes just those. `media/design-chef-portrait.jpg` is cropped from `2022-04-website-nice-shot-650x1024-1-e1767735660571.jpg` (`sips -c 205 205 --cropOffset 112 218`).
