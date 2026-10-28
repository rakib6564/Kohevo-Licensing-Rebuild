# FightxLife install bundle

Installs the FightxLife site (fightxlife.com rebuilt in Kohevo Studio) on a Kohevo install that held the Solaya site,
and removes Solaya completely.

    cd ~/public_html/fightxlife && php ~/fxl-server-bundle/install.php

The bundle can sit anywhere; run it from the site root. It reads only files inside the bundle and uses no network.

## Before you run it

- The site's code must include `feat/studio-native-finish`:
  - bb0f4e1: text settings, the "all but the first" part state, row spans, empty blocks left out;
  - 8a48304: heading wrapping;
  - 60d3df5: images under a sub-folder;
  - 1a936ae: the gallery lightbox closes on × and on the backdrop;
  - 142d39a: the font smoothing setting;
  - 036eb95: the "Sticky header" switch in a header's settings;
  - 35565a2: a pay button lines up with a button beside it.

  Without it install.php stops before changing anything.
- `uploads/` must be writable by the user running the script (it creates `uploads/media/fxl` and deletes
  `uploads/media/solaya-site`).
- The licence must entitle `studio-builder` (the pages) and `forms` (the contact and newsletter forms).
- Take a database backup first. The removal of Solaya cannot be undone without one.

## What it does

1. **Removes Solaya** (first run only): every Studio page, partial and theme template with their revisions,
   compilations, dependencies, locks and page settings. It also removes:
   - the site's own Studio templates (the built-in system presets stay);
   - the design tokens, menus, custom CSS, head and footer snippets, the `studio_global_*` and SEO settings, and the
     site name and branding;
   - the `solaya-contact` form and its submissions;
   - the media rows and files under `uploads/media/solaya-site` and `uploads/branding`;
   - the email sender name is changed from Solaya to FightxLife.

   Users, the licence, plugins and other modules' data are not touched.
2. **Images**: copies the 279 FightxLife images into `uploads/media/fxl` and registers them in the media library.
3. **Build** (`build/site.php`): Site settings › Text and colour tokens, fonts, content types (cours, projet, evenement
   at /events/…, produit at /produit/…) with their fields, 73 pages at the live site's addresses, header, footer, 5
   single templates and 9 redirects. The site's custom CSS is empty: the design is native Studio style.
4. **Branding**: the admin and login logo, the #e9008a accent, the favicon, the login photo and the tagline
   "La force de vivre".

It prints what it removed and what is published. Run it again at any time: the second run removes nothing and
rebuilds the same pages in place.

## Contents

- `install.php`: the entry point.
- `build/`: `site.php` (the site), `lib.php` (helpers), `native.php` (the design as native style), `gap.css` (empty).
- `fxl/`: content.json, image-map.json, extra.json, page-bg.json (the live site's text and image mapping).
- `media/fxl/`: the images, the logo and the site icon.

## The images are not in git

`media/` (279 images, about 180 MB) is left out of the repository. The dev server keeps a full copy of the bundle,
images included, in `~/fxl-server-bundle`. To run the install from this folder, copy that `media/` folder next to
`install.php` first:

    rsync -a rakib-server:fxl-server-bundle/media/ sites/fightxlife/media/

## Deploying a change to the dev server

1. Check that nobody has edited pages since the last run: install.php rebuilds every page and overwrites edits made
   in the editor (look at the newest `created_at` in `studiobuilder_revisions`).
2. Back up the database.
3. Copy this folder to the server (`rsync -rtc --exclude media sites/fightxlife/ rakib-server:fxl-server-bundle/`).
4. Run `cd ~/public_html/fightxlife && php ~/fxl-server-bundle/install.php`.
