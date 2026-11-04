# Chef Gregory's Gourmet Me Now in Kohevo Studio

A candy-shop redesign of gourmetmenow.com (WordPress + WooCommerce) on Kohevo's own Studio and Shop. Built and checked
in a local sandbox only; nothing is deployed and the live site is untouched.

- `INVENTORY.md` is the read-only analysis of the old site: products, prices, sizes, shipping, payments, pages, and the
  questions for Chef Gregory.
- `data/` holds the exports from the old site's database (products, store settings, pages, the Recipes page's
  structure) and `media/` its 286 original images. Both are gitignored; INVENTORY.md says how they were read.
- `build/site.php` builds everything: `cd <site> && php <bundle>/build/site.php`. It needs the Shop (0.4.0: importer,
  shipping zones, tax, variant photos), Studio, Stripe and Media plugins active. It replaces every page it builds, so never rerun it on a site
  someone has edited. `GMN_LIVE=1` lifts the noindex.
- `build/catalog.php` (run by site.php) copies the images into the library and imports the products through Shop's
  WooCommerce importer. Decision of 2026-11-04: one Peanut Brittle product with a flavour choice (the old product 603
  and its 23 variations); the per-flavour brittle products and the 8 oz / 16 oz bourbon product are drafts until
  Chef Gregory confirms prices.
- Shipping and tax (set by catalog.php) follow the old store until Chef Gregory answers: local pickup only for
  Paso Robles, Templeton and Atascadero; USPS rates through EasyPost with the old $3.00 handling fee for the rest of
  the US (the key comes from `GMN_EASYPOST_KEY`; without one, checkout says carrier rates are not available); prices
  before tax with no rate set. The shop page has search and sort; picking a flavour shows its photo.
- `build/orders.php` (run by site.php after the products, or alone: `cd <site> && php <bundle>/build/orders.php`)
  imports the old shop's orders and customers from `data/orders.json` through Shop's order importer: 17 orders
  (6 completed, 5 processing, 6 cancelled) and their customers as guests. The 3,628 failed card-testing orders are not
  in the export. Make the file on the old site with `ssh chefg 'cd ~/public_html/gourmetmenow.com && wp --skip-themes
  eval-file - ' < plugins/shop/tools/woo-export-orders.php > data/orders.json` (reads only). It holds customers'
  details: gitignored, mode 600, never committed. Safe to rerun.
- `build/content.php` holds his own words from the old site and turns the old Recipes page into recipes.
- `build/lib.php` holds the block helpers and the house look as native styles (CARD, PHOTO). There is no custom CSS:
  every block, card, photo, sticker, checkerboard (a 14px tile in the Media library, made by site.php, repeated as a
  background), list marker and form field is styled with Studio's own settings and style parts. Fonts (Fraunces,
  Karla, Caveat) and the store for search engines are Site & SEO settings site.php fills in; the custom code is empty.
  catalog.php takes each product's Google title and description from the old Rank Math (`data/rankmath.json`, read
  from the old database), except the ones in QUESTIONS-FOR-GREGORY.md question 10.

Design: cream, chocolate, butterscotch, kraft and the label's cherry red; the checkerboard of his labels and bandana;
Fraunces for headings, Karla for text, Caveat for the hand-lettered lines.

Addresses kept: `/`, `/products/`, `/brittles/`, `/recipes/`, `/about-us/`, `/gallery/`, `/contact-us/`,
`/product/<slug>/` (every product keeps its WooCommerce slug), `/cart/`, `/checkout/`. New: `/order-received/`.
Redirected: `/evercompare/`, `/my-account/`, `/product-category/brittles/`, `/product-category/gourmet/`.

Held back on purpose (see QUESTIONS-FOR-GREGORY.md): the phone number, the Templeton address, and the hours that
the old site gave two ways.
