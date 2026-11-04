# Chef Gregory's Barbecue Smokehouse Emporium in Kohevo Studio

A redesign of chefgquesbbq.com, built from public pages only. Not deployed.

- `build/capture.sh` saves the live pages, photos (`media/`) and old upload files (`media/legacy/`). `data/` and `media/` are gitignored.
- `build/content.php` holds the business facts and every menu item. Prices come from the counter menu of 9-24-26.
- `build/site.php` builds everything: `cd <site> && php <bundle>/build/site.php`. It replaces every page it builds, so never rerun it on a site someone has edited. `GQ_LIVE=1` lifts the noindex.
- `build/lib.php` holds the block helpers, `build/design.css` the little native style cannot say.
- `build/recipes.php` adds the **Recipes** type (`/recipes/<slug>/`), its Single template and one item per `build/recipe-*.json`, and appends `build/recipes.css` to the site's stylesheet. It touches nothing else, so it is safe on an edited site; run it again after `site.php`, which replaces the content types and the stylesheet. Recipe text is Gregory's own, published as he wrote it; its photos (`media/gq-brisket-*.webp`) come from his WhatsApp batch of 3 Nov, not from `capture.sh`.

Content types: **Menu Items** (`/menu/<slug>/`; section, menu line, sizes repeater, availability, plate note, story, photo) and **Locations** (one item at `/visit/`; address, phone, hours repeater, notes, payment, questions repeater). Hours in the footer, home and Order page all read the Location item. Query Loops use the item's section as the category; a loop with no category inherits the current item's, so always set one.

Addresses kept: `/`, `/menu/`, `/feed/`, `/#about`, `/#menu`, `/wp-content/uploads/*` (copy the whole folder at the switch). `/category/uncategorized/` 301s to `/menu/`. `/sitemap.xml` and `/robots.txt` now work.

Do not use `Picture-130`: it is the "Willingham Major Awards" plaque, 1983-2001, not Chef Gregory's record.
