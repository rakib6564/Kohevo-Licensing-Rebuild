# gourmetmenow.com: inventory for the Kohevo migration

Read on 2026-11-04 from the live WordPress database on `chefg` (`~/public_html/gourmetmenow.com`, database `gourmetmenow`). Everything was read only. Nothing on the site was changed.

Files next to this one:

| File | What |
|---|---|
| `products.csv` | 31 products and 25 variations, one row each: price, sale price, stock, weight (oz), length × width × height (in), category, tax status, sales, image files, descriptions |
| `data/products.json` | the full catalogue export: every product field, attribute, variation and meta value, plus categories |
| `data/store.json` | store settings, shipping zones, tax, coupons, order counts, plugins |
| `data/ship.json` | EasyPost, Stripe and PayPal settings, with secrets shown as "(set, hidden)" |
| `data/pages.json` | the 11 pages, the Elementor templates, the menu, SEO fields, brand kit, and the list of 286 images |
| `media/` | the 286 original images from `wp-content/uploads`, in their year/month folders (51 MB) |

`data/` and `media/` are gitignored, as in the other site bundles.

## 1. State of the live site

- **The whole site is in maintenance mode.** Every public address answers **503** with an "under maintenance" page, so nobody can browse or buy. The only exception is `/sitemap_index.xml`, which answers 200.
- **No payment method is switched on.** The Stripe ("Payment Plugins for Stripe WooCommerce") and "WooCommerce PayPal Payments" plugins are installed but **inactive**. Stripe's card method is set to disabled. Even with the site open, checkout could not take money.
  - The Stripe account is connected (`acct_1HKdavAg8QSHTxLv`, live mode, keys stored).
  - PayPal is connected to the merchant `greg@gems2020.com`, a live business account.
- **3,628 failed orders** between 7 Feb 2025 and 1 Apr 2026. That pattern is card-testing fraud, not customers, and is probably why the site was closed and payments turned off.
- **Real orders:** 11 completed or processing orders, $623.61 in total (first 21 Jan 2024, last 26 Mar 2026). There are also 6 cancelled. I didn't export any customer details.
- **Software:** WordPress with the Astra theme 4.13.3, Elementor 4.0.9 + PRO Elements, WooCommerce 10.7.0 and Rank Math. Also installed: ShopLentor, JustTables, ELEX EasyPost, ShipStation, WPCode and WP File Manager. WP File Manager is a security risk on a live site.

## 2. Business information

| | Value | Source |
|---|---|---|
| Site name | "Best Peanut Brittles in California", tagline "Gourmet Me Now" | WordPress settings |
| Brand used on the pages | Gourmet Me Now · Chef Gregory's (Chef Gregory Kalatsky) | pages |
| Store address | 1720 Ironwood Pl, Templeton, CA 93465 (the city field is saved as "Templeton,") | WooCommerce. **Confirm whether this is a home address before publishing it.** |
| Phone in the pickup text | the 949 number on his hold list | local pickup method title. **This number is on Gregory's hold list ("do not publish it anywhere") in the master prompt.** |
| Shop emails | sender `brittle@chefgregory.com` (name "Chef Gregory Kalatsky"); admin `chef@chefgregory.com`; low-stock alerts go to `rakib0493@gmail.com` | WooCommerce |
| Hours | "Open for business by appointment only. Please call to arrange local pickup. Office hours are Monday-Friday 9 AM- 4 PM." | Contact page. **Rank Math's schema says Mon–Sun 9–5, which contradicts it.** |
| Founded | "Chef Gregory's EST 1997" | About page |
| Schema | Organization "Chef Gregory's", logo `2023/07/Chef-Gregorys-Logo.jpg` | Rank Math |
| Logo / icon | `2021/12/cropped-logo-transperant-1-1.jpg`; favicon `2020/02/257204279_…_n.jpg` | theme |
| Elementor kit | the Elementor defaults (#6EC1E4, #54595F, #7A7A7A, #61CE70; Roboto / Roboto Slab), so no real brand palette exists | Elementor |
| Other links | YouTube videos on the Recipes page (sizzle reel `youtu.be/sVYeMhKAH4E`, roast chicken `youtu.be/GQ_4_wqMo-4`) | pages |

Currency USD, price on the left, 2 decimals. Weights are in **ounces**, sizes in **inches**. The shop sells and ships to the **US only**.

## 3. Catalogue

3 categories: **Brittles** (21), **gourmet** (5), **Uncategorized** (4). There is one global attribute, "Ingredients", with no values. No product has a SKU. Reviews are turned off.

### Products (30 published, 1 draft)

| ID | Product | Type | Price | Weight oz | L×W×H in | Category | Sales |
|---|---|---|---|---|---|---|---|
| 603 | World-famous peanut brittle!! Over 20 flavors | variable (flavour) | $28.97–35.00 | 16.05 | 12×7×3 | Brittles | 31 |
| 601 | Ginger Soy Dressing (LOW-FAT TOO!!!), 13 oz bottle | simple | 9.97 | 15 | 11.5×1.7×0.18 | gourmet | 54 |
| 599 | Southwestern Flair LOW SODIUM Seasoning (5 oz, sifter lid) | simple | 9.97 | 8 | 5×2.25×5 | gourmet | 18 |
| 598 | Southwestern Flair Seasoning LOW SODIUM, Mild with a little kick | simple | 9.97 | 8 | 5×2.25×5 | gourmet | 15 |
| 600 | Chef Gregory's Southwestern Flair LOW SODIUM (bigger pack) | simple | 19.97 | 8 | 5×5×3 | gourmet | 0 |
| 602 | Chocolate Puddle Brownie Cake (about 2¾ lb, feeds 8) | simple | 37.95 | 40 | 11×9×3 | gourmet | 1 |
| 615 | English Toffee (8 oz bag) | simple | 29.95 | 8 | 8.68×5.43×1.75 | Uncategorized | 8 |
| 617 | Vegan Toffe [sic] | simple | 19.97 | 8 | 8×2.5×5 | Uncategorized | 1 |
| 616 | Vegan Peanut Brittle | simple | 12.97 | 8 | 8×2.5×5 | Brittles | 10 |
| 2233 | Old-Fashioned Peanut Brittle | simple | 16.00 | 8 | 8.68×5.43×1.75 | Uncategorized | 8 |
| 2167 | Oh my God someone dial 911!! SPICY Peanut Brittle | simple | 15 | 6 | 7×7×3 | Uncategorized | 0 |
| 2178 | Bourbon/Whiskey Peanut Brittle | variable (8 oz / 16 oz) | $35 (16 oz); **8 oz has no price** | 16.1 / 8 | 16×10×4 | Brittles | 2 |
| 2147 | Great ol Regular peanut brittle | simple | 25 | 16.05 | 8.68×5.43×2 | Brittles | 2 |
| 2145, 2146 | Habanero roasted garlic peanut brittle / Habanero Roasted Garlic (**duplicates**) | simple | 29.00 | 16.1 / 16.05 | 10×8.625×2 / 8.68×5.43×2 | Brittles | 0 |
| 2139, 2140, 2142, 2153 | Toasted coconut · Lemongrass lime leaf · Maple Bacon · Barbecue Smokehouse | simple | 29.00 | **none** | **none** | Brittles | 0 |
| 2149 | Pumpkin Pie (holiday) | simple, **out of stock** | 29.00 | 16.05 | 10×5.43×2 | Brittles | 0 |
| 2152, 2154, 2155, 2157 | Peppermint Chocolate · Cappuccino Tiramisu · Chipotle with adobo · Mint Chocolate | simple | 29.00 | 16.05–16.1 (Peppermint none) | 10×8.625×2 | Brittles | 0 |
| 2158, 2161, 2162, 2164, 2165, 2166 | Banana Bacon · Peanut Butter · The Elvis Special · Thai Ginger · Vanilla Butter · Southwestern Flair-MILD | simple | 29.00 | 16.05–16.1 | 16×10×4 | Brittles | 0–1 |
| 2222 | Cooking Classes ($85 per person plus food, 2–3 hours) | simple, **draft**, out of stock | 85 | none | none | Uncategorized | 0 |

### Variations of 603 "World-famous peanut brittle" (attribute "Choose Your Flavor", 23 variations)

| Price | Flavours |
|---|---|
| $28.97 | Cappuccino Tiramisu (also an odd box size, 12.5×7×9.5) |
| $29.00 | Toasted Butter Pecan, Toasted coconut, Chipotle with Adobo, Mint Chocolate, Banana Bacon, Vanilla Butter, Southwestern Flair-Spicy, Cinnamon Cayenne, Lemongrass- Lime, Habanero – Roasted Garlic, Maple Bacon, Thai Ginger, Pumpkin Pie for the Holidays, Southwestern Flair Mild, Regular, Barbecue Smokehouse |
| $31.00 | Peanut Butter |
| $32.00 | The Elvis Special!, Peppermint Chocolate |
| $35.00 | Mind Haze Double IPA, Bourbon Whiskey, Big Sur IPA (double) from BarrelHouse |

The attribute also lists 5 values that no variation uses: "Lemongrass Lime Leaf", "Habanero Roasted Garlic", "Maple bacon", "How about a Little "FIRE!" Scarecrow" and "Oh my God, Someone Dial 911!!!". Every variation is in stock, with no stock count kept.

### Catalogue problems to settle before migrating

1. **The flavours are sold twice.** Most flavours exist both as a variation of 603 and as their own product (for example Thai Ginger is variation 610 and product 2164). Habanero also has two products of its own. Decide on one model: one "Peanut Brittle" product with a flavour choice (and maybe a size choice), or one product per flavour.
2. **Prices disagree:**
   - Regular brittle is $29 as a variation, $25 (2147) and $16 (2233) as products.
   - Peanut Butter is $31 as a variation and $29 as a product.
   - Elvis and Peppermint are $32 as variations and $29 as products.
   - Bourbon is $35, and its 8 oz option has no price at all.
   - The 911 brittle is $15 for 6 oz.
3. **Sizes and weights disagree:**
   - The same 1 lb brittle is listed in 4 box sizes (12×7×3, 10×8.625×2, 8.68×5.43×2, 16×10×4).
   - 5 products have no weight or size, which breaks live shipping rates.
   - The Ginger Soy bottle is 11.5×1.7×0.18 in, which can't be right.
4. **Tax status varies:** 21 products are taxable, 9 are not and 1 is "shipping only". No tax rates are set anyway, so no tax is ever charged. California taxes candy and confectionery, so this needs Gregory's or his accountant's answer.
5. **Names and copy:**
   - HTML is typed into product names (`<b>LOW SODIUM</b>`).
   - Typos: "Toffe", "Hre".
   - Three Southwestern Flair products have near-identical names, so it isn't clear which is mild, spicy or "911".
   - English Toffee's text describes "our vegan butter".
   - The home page says "one of the Best Catering Services", which belongs on the catering site.
6. **The copy needs Gregory's approval:**
   - Several brittle descriptions say "award-winning" and read like generated text.
   - The About page credits Europe training "by the same chef that trained Wolfgang Puck".
   - His content rule applies: publish his own text only, nothing invented.

## 4. Shipping

- **EasyPost (ELEX plugin), enabled for the US:**
  - Live rates for **USPS First-Class and Priority Mail**, each marked up by $3; Priority also has 30% in the percent field.
  - The FedEx, UPS and Canada Post services are ticked in the plugin, but only USPS First and Priority are enabled at the carrier level.
  - Packing: box packing, heaviest first.
  - The rate shown at checkout depends on each product's weight and size, which is why the missing ones above matter.
- **EasyPost boxes** (inches; max weight presumably in lb):

  | Box | Size | Max weight |
  |---|---|---|
  | Envelope | 12.5×9.5×1 | 4.2 |
  | Small Box | 8.687×5.437×1.75 | 1 |
  | Medium Box | 11.25×8.75×6 | 6.5 |
  | Large | 12.25×12.25×6 | 11 |

- **Local pickup:** a zone for CA postcodes 93446, 93465 and 93422 (Paso Robles, Templeton, Atascadero), free. Its title asks people to book an appointment on the 949 number on his hold list.
- **Rest of the world:** no methods, so only US addresses can check out.
- **ShipStation:** installed and connected (auth key set), for labels and fulfilment.
- **Shipping classes:** none.
- **English Toffee's text** promises "flat rate priority small mailbox" shipping.

## 5. Payments, tax, coupons

- **Payments:**
  - **Stripe:** connected, live mode, cards set to Amex, Discover, Visa and Mastercard. The plugin and the card method are both off.
  - **PayPal Payments:** connected to the merchant `greg@gems2020.com`. Venmo and Pay Later are off, and the plugin is off.
- **Tax:** "calculate taxes" is on and is based on the shipping address, with prices shown before tax. **No tax rates are set**, so tax is $0 on every order.
- **Coupons:** `gmn15`, 15% off, expired 31 Dec 2023, never used.
- **Checkout:** guest checkout allowed, stock management on (low-stock alert at 2). No terms page is set.

## 6. Pages, menu, SEO

**Main menu:** Home · About Us · Products · Brittles · Recipes · Gallery · Contact Us.

| Page | Address | What is on it |
|---|---|---|
| Home | `/` | hero "Welcome to our website of Whimsical Creations", featured products with Buy-now buttons |
| About Us | `/about-us/` | the Southwestern Flair story (Orange County School of Culinary Arts, 1999, "Chill'n and Grill'n" class), the seasonings' low-sodium claims, Chef Gregory's catering philosophy (about 10,000 characters) |
| Products | `/products/` (WooCommerce shop page) | Gourmet Me Now intro, Chef Gregory's training story (repeated twice) |
| Brittles | `/brittles/` | product grid of all brittles, "Over 20 flavors" |
| Recipes | `/recipes/` | Gregory's Southwestern Flair recipes: burgers, guacamole, pico de gallo, whole roast chicken and more, with 2 YouTube videos |
| Gallery | `/gallery/` | "Our Delicious Products Gallery" (images only) |
| Contact Us | `/contact-us/` | hours, "Click to Call", a Gravity Forms form (#1), a link to the catering site |
| Cart / Checkout / My Account | `/cart/`, `/checkout/`, `/my-account/` | WooCommerce pages |
| EverCompare | `/evercompare/` | a leftover product-compare page from ShopLentor |

- **Elementor templates (17 published):**
  - the single product template, a custom header and the under-maintenance page;
  - a popup ("gems");
  - section blocks (hero video, pricing list, featured product, gallery, services, features, "other website" section).
- **Product addresses:** `/product/<slug>/`, categories at `/product-category/<slug>/`.
- **SEO titles (Rank Math):** the titles don't match their pages; for example the Cart page targets "Ginger Soy Salad Dressing" and My Account targets "Soft Peanut Butter Brittle". They should be rewritten.

## 7. Images

- **286 originals** (jpg, png, webp), all with alt text. They're in `media/` under their upload dates (2021/12: 92, 2022/02: 37, 2023/03: 131, 2023/04: 15, others: 11).
- **Product photos:** every product has a main image; the older products also have galleries (up to 9). The photo of each product and variation is listed in `products.csv`.

## 8. Mapping to Kohevo (Studio + Shop + payments)

| WooCommerce | Kohevo Shop today (plugin 0.2.0) | Gap |
|---|---|---|
| Simple product, price, sale price, stock, gallery, short and long description, categories, SKU | `shop_products` (price, sale price, stock, images_json, descriptions, categories, SKU) | none |
| Variable product (flavour, size) | `shop_variants` (one row per combination, own price, sale price, stock, image, SKU) | none. Note: Shop's README still lists variants under "Not in this version" and needs a fix |
| Weight and L×W×H | **no fields** | **gap**: needed for carrier rates |
| EasyPost live USPS rates, box packing | one flat rate with an optional free-shipping threshold, or free pickup | **gap**: choose flat rates per order (or by weight band) or build carrier rates |
| Local pickup limited to 3 postcodes | pickup is offered to everyone | small gap: the pickup note must say local only |
| US sales tax excluded from prices, by address | prices include VAT; no tax rates | **gap** if California tax applies |
| Stripe | Stripe Checkout (hosted) through stripe-payment | none (new keys, or the same account) |
| PayPal | gateway-neutral payments layer + paypal-payment plugin, PRs #229–#234 **open, not merged** | waits on those PRs |
| Coupons | not supported | the only coupon expired, so not needed now |
| Product addresses `/product/<slug>/` | Shop serves `/product/{slug}` (prefix is a setting) | the same addresses can be kept |
| Rank Math SEO, sitemap | Studio SEO screen, sitemap, robots | none |
| Gravity Forms contact form | Kohevo Forms | none |
| Elementor pages | Studio pages with native widgets, redesigned as for chefgregory.com and chefgquesbbq | redesign |

## 9. Questions for Gregory before the build

1. **Product model:** one "Peanut Brittle" product with a flavour choice, or one product per flavour? Which flavours are still made? Which sizes (8 oz, 16 oz)?
2. **Prices:** the price for each flavour and size. This settles the $25, $16 and $29 regular-brittle prices, the $28.97 tiramisu, and the missing 8 oz bourbon price.
3. **Box sizes and packed weights:** one each for brittle, toffee, seasoning, dressing and brownie cake.
4. **Shipping:** keep live USPS rates or use flat rates? Which flat amounts? Should there be a free-shipping threshold?
5. **Sales tax:** does he collect California sales tax on candy and seasonings?
6. **Pickup:** is local pickup still offered, and which phone number goes in it (the 949 number is on his hold list)?
7. **Address:** may the Templeton store address be shown on the site?
8. **Payments:** reopen with Stripe, PayPal or both? Card testing is why it was closed, so the new checkout needs protection, such as Stripe Checkout's built-in protection or a rate limit.
9. **Copy:** approval of the product and About text, including the "award-winning" claims and the Wolfgang Puck sentence, and whether the Recipes page moves over as it is.
10. **Cooking Classes:** a draft $85 product. Sell it, move it to the catering site, or drop it?
