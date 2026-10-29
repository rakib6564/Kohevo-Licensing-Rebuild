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
- The licence must entitle `studio-builder` (the pages), `forms` (the contact and newsletter forms) and `shop` (the
  e-shop), and the Shop and stripe-payment plugins must be active. Without Shop, install.php stops before changing
  anything.
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
3. **Build** (`build/site.php`): Site settings › Text and colour tokens, fonts, content types (cours, projet, article,
   evenement at /events/…) with their fields, the pages at the live site's addresses, header, footer, 5 single
   templates and the redirects. The site's custom CSS is empty: the design is native Studio style.
   The **e-shop** is the Shop plugin (`fxl/shop.json`, from the live WooCommerce store API): the T-shirt, the Sweat à
   Capuche and the Casquette with their colour and size variants, at /produit/{slug}/; the Product Grid on /e-shop/,
   /boutique/ and the home page; the cart at /panier/, the checkout at /commande/ and /commande-recue/ (the live
   addresses); the Produit Single template; and a mini cart in the header. A product already in Shop is left as it
   is, so prices and stock changed in Shop › Products survive a rebuild. Shipping is a 5,00 € flat rate (a
   placeholder: the live shop ships with Mondial Relay by weight) or free pickup.
4. **Branding**: the admin and login logo, the #e9008a accent, the favicon, the login photo and the tagline
   "La force de vivre".

It prints what it removed and what is published. Run it again at any time: the second run removes nothing and
rebuilds the same pages in place.

## V2 pages (preview beside the current site)

The V2 content pack (October 2026: 0. Menu, 1. Accueil … 6. Contact, and a 301 plan) is built in the client-approved
V2 design **beside** the current pages, which stay as they are. Each V2 page lives at `<slug>-v2`, for example
`/cours-v2/` next to `/cours/`, with the home page at `/accueil-v2/`. The V2 header, footer and menus (`v2-*`) show
only on those pages: their display conditions list the V2 slugs. Every link between V2 pages points at the `-v2`
addresses (`V2URL()` in v2.php). The build creates and updates only its own pages, so it is safe to run after
editor changes:

    cd ~/public_html/fightxlife && php ~/fxl-server-bundle/build/v2.php

- `fxl/v2-content.json`: the documents' text as data (SEO title and description, H1, intro, H2 sections with their
  H3 blocks, paragraphs, lists and labelled calls to action). Rebuild it from the client's zip:
  `python3 build/v2parse.py "<zip>" fxl/v2-content.json`.
- `build/v2.php`: the home page, header, footer, menus, the Homemade Apple font and the design (`native_v2()`).
- `build/v2-pages.php`: the page engine for the 21 other pages (`native_v2_pages()`, `v2_build_pages()`). It also holds
  each page's photos (`V2_PAGE_IMAGES`).

**The switch** (`--live`, done on the dev site on 2026-10-29). V2 takes the real addresses and nothing is deleted:

    cd ~/public_html/fightxlife && php ~/fxl-server-bundle/build/v2.php --live

- each current page whose address V2 takes moves to `<slug>-v1` (the home page to `/accueil-v1/`) and keeps the
  current header and footer there; the V2 header and footer show everywhere else (articles, events and shop too);
- the 301 plan is installed (`V2_RETIRED`), plus a 301 from each `-v2` preview address to its page; links to a
  retired address go straight to its destination;
- the V2 pages drop their own noindex (the site's robots setting still decides).

Running `v2.php` without `--live` moves every page back, so the switch can be undone. Pages are renamed through
Studio, keeping their revisions; V2's page ids are in the `fxl_v2_pages` setting.

**Donations and contact.** The donation section has one-off and monthly panels, each with set amounts and a free
amount (5–5 000 €), returning to `/merci-don/`. Monthly gifts are Stripe subscriptions: on the production Stripe
account, add `invoice.paid` to the webhook's events so renewals are recorded. Contact links carry `?reason=`
(the motif), and `?activity=`, `?project=`, `?subject=` (the object), filled in by the page (see `V2_REASONS`).

**Still from the client:** the full-size Benkick mascot photo (hero) and the Cera hospital video, a free-swimming
photo for Nage libre, any partner beyond La Roche-Posay and Cera, and confirmation of the team (`V2_TEAM`, taken from
the live site's Notre Équipe; empty it to hide the section).

## Contents

- `install.php`: the entry point.
- `build/`: `site.php` (the site), `v2.php`, `v2-pages.php` and `v2parse.py` (the V2 pages), `lib.php` (helpers), `native.php` (the design as native style), `gap.css` (empty).
- `fxl/`: content.json, image-map.json, extra.json, page-bg.json (the live site's text and image mapping).
- `media/fxl/`: the images, the logo and the site icon.

## The images are not in git

`media/` (279 images, about 180 MB) is left out of the repository. The dev server keeps a full copy of the bundle,
images included, in `~/fxl-server-bundle`. To run the install from this folder, copy that `media/` folder next to
`install.php` first:

    rsync -a rakib-server:fxl-server-bundle/media/ sites/fightxlife/media/

## Only the shop

`php ~/fxl-server-bundle/install.php --shop` copies any new images and builds only the shop: the products, the Shop
settings, /e-shop/, /boutique/, /panier/, /commande/, /commande-recue/, the Produit template and the header. Use it when
pages have been edited in the editor since the last full run.

## Deploying a change to the dev server

1. Check that nobody has edited pages since the last run: install.php rebuilds every page and overwrites edits made
   in the editor (look at the newest `created_at` in `studiobuilder_revisions`).
2. Back up the database.
3. Copy this folder to the server (`rsync -rtc --exclude media sites/fightxlife/ rakib-server:fxl-server-bundle/`).
4. Run `cd ~/public_html/fightxlife && php ~/fxl-server-bundle/install.php`.
