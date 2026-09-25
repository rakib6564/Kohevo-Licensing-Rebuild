# Current Implementation — Modules (As-Built)

**Status:** Living reference · **Describes:** the 19 installed plugins today ·
tables/permissions/admin-surface **verified** by extraction.

Per-plugin inventory of the *current* plugins (Slate calls them "plugins" today;
the target calls verticals "modules"). Each entry: **purpose · status/version ·
tables · permissions · admin/public surface · key hooks · global API · dependencies
· problems · migration plan**. Cross-plugin calls today are `class_exists('<X>API')`
on global classes. Versions/status from the `plugins` registry.

> Legend: **A** = active, **I** = inactive (installed, available).

---

## Commerce

### shop — `1.2.7` · I
- **Purpose:** full e-commerce — products w/ variants, categories, cart, checkout,
  orders, coupons, reports, CSV import/export.
- **Tables (5):** `shop_products`, `_customers`, `_orders`, `_order_items`,
  `_coupons`.
- **Permissions:** `shop.{view_orders,manage_orders,manage_products,manage_coupons,view_reports,manage_settings}`.
- **Surface:** 8 admin pages; public storefront (index/category/product/cart/checkout/order, order view-token protected).
- **Hooks:** provides `shop_shipping_rate`, `shop_payment_providers`,
  `shop_order_created`, `shop_order_status_changed`, `shop_product_db_row`,
  `shop_cart_shipping_breakdown`.
- **API:** `ShopAPI` (~40 methods).
- **Deps:** stripe-payment (payments), a shipping plugin, content-builder (blocks).
- **Problems:** **money in `DECIMAL`** (vs cents elsewhere); own `shop_customers`
  (not linked to core `customers`); checkout has no coupon field though API supports
  it; bidirectional coupling with stripe-payment.
- **Migration:** → `Slate\Module\Shop` on Money + `PaymentGateway` (Phase 3);
  `shop_customers` → Contact profile (Phase 2).

### stripe-payment — `1.2.0` · I
- **Purpose:** generic Stripe gateway (hosted + embedded), webhook handler, charges
  ledger w/ refunds, test/live toggle.
- **Tables (2):** `stripepayment_sessions`, `stripepayment_charges` *(prefix ≠ slug)*.
- **Permissions:** `stripe.manage_settings`.
- **Surface:** 2 admin pages; public `webhook`/`return`/`success`/`create-intent`.
- **Hooks:** fires `stripe_webhook_event`.
- **API:** `StripePaymentAPI` (+ `StripeTerminalAPI` for restaurant).
- **Deps:** consumed by shop, booking, membership, restaurant, clientdesk.
- **Problems:** **bidirectional coupling** — its public endpoints hard-depend on
  `ShopAPI`/`isActive('shop')`; secrets encrypted at rest (good); publishable key
  plaintext (by design).
- **Migration:** → `Slate\Services\Payments` (`PaymentGateway`), decoupled via
  `payment.succeeded` (Phase 3).

### shop-emails — `1.0.0` · I
- **Purpose:** order-received/paid/completed/admin-new-order emails, overridable
  templates.
- **Tables:** none (settings-only). **Settings prefix `shop_email.` ≠ slug.**
- **Permissions:** `shop_emails.manage`. **Deps:** shop + SMTP.
- **Problems:** synchronous sends. **Migration:** → Notifications service (Phase 3).

### membership — `0.7.1` · A
- **Purpose:** fixed-term/recurring memberships, plans, wallet, billing; integrates
  booking + Stripe.
- **Tables (5):** `membership_plans`, `_subscriptions`, `_profiles`, `_wallet`,
  `_wallet_txns`. **Correctly keys off core `customer_id`.**
- **Permissions:** `membership.{view,manage_plans,manage_members,manage_settings}`.
- **Surface:** 5 admin pages; member portal.
- **API:** `MembershipAPI` (facade). **Deps:** booking, stripe-payment,
  media-library, forms (`works_better_with`).
- **Problems:** runs `ensureSchema()` **unconditionally** on its dashboard widget;
  money in cents (good).
- **Migration:** → `Slate\Module\Membership` (`membership@1`) on Identity/Payments
  (Phase 3).

## Scheduling

### booking — `0.5.1` · A
- **Purpose:** full appointments — services/add-ons/custom-fields, providers
  w/ hours/breaks/overrides, capacity/recurring/walk-in, locations+resources, admin
  calendar, `/book` widget, reminders, Stripe pay, coupons/gift cards, GDPR.
- **Tables (14):** `booking_services`, `_providers`, `_provider_services`,
  `_provider_hours`, `_appointments`, `_customers`, `_categories`, `_locations`,
  `_resources`, `_service_resources`, `_service_addons`, `_custom_fields`,
  `_provider_breaks`, `_date_overrides` — **owned solely by booking (verified)**.
- **Permissions:** `booking.{view,manage_services,manage_providers,manage_appointments,manage_resources,manage_settings,manage_payments}`.
- **Surface:** 18 admin pages (calendar, appointments, services, providers,
  resources…); public `/book` (+ `/book/done` payment verify).
- **Hooks:** provides `booking_can_book`, `booking_slot_allowed`,
  `booking_created/paid/cancelled/rescheduled/status_changed`,
  `booking_reminder_body`.
- **API:** `BookingAPI` (~50 methods). **Deps:** stripe-payment, notifications
  (SMS/WhatsApp via Twilio).
- **Problems:** own `booking_customers` (not linked to core `customers`); some
  availability queries omit `tenant_id`; slot engine race-safe (`FOR UPDATE`, good).
- **Migration:** → `Slate\Module\Booking` (`booking@1`) on Contacts/Money (Phase 3).

### booking-plus — `0.2.0` · A
- **Purpose:** booking add-on layer (extra config, slot restrictions, appointment
  meta, messaging).
- **Tables (3):** `bookingplus_service_config`, `_slot_restrictions`,
  `_appointment_meta` — **its own prefix (verified).**
- **Permissions:** `bookingplus.{manage_settings,reply_messages}`.
- **Surface:** 5 admin pages. **Deps:** booking — reaches it via
  `class_exists('BookingAPI')` (graceful degrade), **not** its tables.
- **Migration:** folds into `Slate\Module\Booking` or a booking extension (Phase 3).

## Content & site

### content-builder — `1.6.1` · A
- **Purpose:** WP-style block CMS — post types, taxonomies, menus, blocks, theme/
  branding; the real block spine (`BlockRegistry` + `Renderer`).
- **Tables (7):** `contentbuilder_post_types`, `_posts`, `_post_meta`,
  `_taxonomies`, `_terms`, `_term_relations`, `_menus` *(prefix ≠ slug)*.
- **Permissions:** **none declared** in manifest (verify gating).
- **Surface:** 7 admin pages; public `/p/<slug>`, `/<type>/<slug>` (draft preview).
- **Hooks:** provides `content_register_blocks`, `content_register_patterns`,
  `content_edit_sidebar`, `content_head_tags`, `content_footer`, `content_save_post`.
- **API:** `ContentBuilderAPI`, `BlockRegistry`.
- **Problems:** `rx-*` restaurant blocks baked into core registry; SBK `sb-*`
  parallel system; `full_html` bypasses theme; legacy `public/render.php` duplicate;
  "layout" is a flat JSON block array (no Section/Template).
- **Migration:** → `Slate\Module\WebsiteCms`; `BlockRegistry`/`Renderer`/`Theme`
  **promoted to core** `Slate\Presentation` (Phase 4).

### seo — `1.1.0` · I
- **Purpose:** per-page meta/OG/Twitter/canonical/noindex + sitemap.xml/robots.txt/
  JSON-LD via content-builder hooks.
- **Tables (1):** **`seo_settings`** — **bypasses the core `settings` table.**
- **Permissions:** none declared. **Deps:** content-builder (post-meta,
  `content_head_tags`).
- **Migration:** → `Slate\Services\Seo` (`SeoMetaProvider`) (Phase 4).

### sitehub — `1.0.0` · I
- **Purpose:** **NOT a site builder** — remote control plane for external
  **WordPress** fleets via a PortKit HTTPS API (monitoring/health/backup/push).
- **Tables (3):** `sitehub_sites`, `_runs`, `_backups`.
- **Permissions:** `sitehub.{view,manage}`. **Surface:** 2 admin pages.
- **Migration:** out of scope for the platform consolidation (standalone tool).

### small-business-kit — `0.1.0` · A
- **Purpose:** hooks-only kit — `sb-*` blocks/theme/chrome injected over
  content-builder pages.
- **Tables:** none. **Permissions:** `sbk.theme`. **Deps:** content-builder, forms.
- **Problems:** parallel `sb-*` block/theme/chrome vocabulary (duplication).
- **Migration:** reconcile into the one Presentation system (Phase 4).

## Communication

### forms — `0.7.5` · A
- **Purpose:** form builder — ~23 field types, e-signature, PDF gen, conditional
  logic, multi-step, webhooks (SSRF-guarded), CSV export, honeypot + rate-limit.
- **Tables (5):** `forms_definitions`, `_submissions`, `_webhooks`, `_webhook_log`,
  `_spam_log`.
- **Permissions:** `forms.{view,manage,export}`. **Surface:** 7 admin pages;
  public `/forms/<slug>` + iframe.
- **Hooks:** fires `forms_submitted`. **API:** `FormsAPI`.
- **Problems:** coexists with legacy core contact-forms (retire one).
- **Migration:** → `Slate\Module\Forms`; retire legacy core forms (Phase 3).

## Other verticals

### coaching — `0.6.0` · A
- **Purpose:** coaching programs — goals, diary (food/photo/hydration), chat,
  meal plans, recipes, challenges (largest plugin).
- **Tables (16):** `coaching_*` (profile, goal, goal_checkin, diary_*, hydration,
  activity, thread, message, meal_structure, shopping_list, recipe, challenge,
  summary). **Keys off core `customer_id`.**
- **Permissions:** `coaching.{view_clients,manage_clients,reply_chat,manage_library}`.
- **Surface:** 8 admin pages. **API:** `CoachingAPI`. **Deps:** membership, booking,
  booking-plus, media-library, forms (`works_better_with`; via `class_exists`).
- **Migration:** → `Slate\Module\Coaching` (Phase 3+).

### clientdesk — `2.1.2` · I
- **Purpose:** client-services desk — clients, projects, milestones, quotes,
  invoices, tickets, files.
- **Tables (14):** `clientdesk_*`. Own `clientdesk_clients` (duplicate person table,
  nullable `customer_id` link).
- **Permissions:** `clientdesk.{view,manage_clients,manage_projects,manage_quotes,manage_invoices,manage_team,handle_support}`.
- **Surface:** 10 admin pages. **API:** `ClientDeskAPI`. **Deps:** stripe-payment.
- **Problems:** `clientdesk_clients` not linked to core `customers`; `total_cents`
  (good).
- **Migration:** clients → Contact profiles (Phase 2); → `Slate\Module\ClientDesk`.

### restaurant — `0.2.0` · I
- **Purpose:** SpotOn-style single-location restaurant — menu, modifiers, floor/
  tables, POS orders, Stripe Terminal.
- **Tables (13):** `restaurant_*` (menu_categories, items, modifier_groups,
  modifiers, item_modifier_groups, sections, tables, customers, orders, order_items,
  order_item_modifiers, payments, readers) — **own prefix (verified).**
- **Permissions:** `restaurant.{view,pos,manage_menu,manage_floor,manage_orders,manage_customers,manage_settings,reports}`.
- **Surface:** 12 admin pages. **API:** `RestaurantAPI`. **Deps:** stripe-payment +
  `StripeTerminalAPI`.
- **Problems:** own `restaurant_customers` (unlinked); `rx-*` blocks live in the
  core content-builder registry.
- **Migration:** customers → Contacts (Phase 2); `rx-*` blocks → this module
  (Phase 4).

### timeclock — `1.2.0` · I
- **Purpose:** staff time clock — employees, sites, tasks, active punches, entries.
- **Tables (5):** `timeclock_*`. **Permissions:** `timeclock.{view,manage}`.
  **Surface:** 7 admin pages.
- **Migration:** → `Slate\Module\Timeclock` (Phase 3+).

### survey-pipeline — `1.0.0` · I
- **Purpose:** survey → order pipeline (connections, orders, events).
- **Tables (3):** `surveypipeline_*` *(prefix ≠ slug)*. `quoted_amount DECIMAL`.
- **Permissions:** `surveypipeline.{view,manage,admin}`. **Surface:** 2 admin pages.
- **Migration:** → `Slate\Module\SurveyPipeline` (Phase 3+).

## Infrastructure / shims

### media-library — `1.1.0` · A
- **Purpose:** **REQUIRED compatibility shim** — media promoted to core
  (`includes/Media.php`); this exposes the picker adopters use.
- **Tables (1):** `medialibrary_files` (legacy; superseded by core `media_files`).
- **Permissions:** none declared (uses core `media.*`). **Surface:** 2 admin pages.
- **Problems:** **must stay active** — deactivating breaks shop product editor etc.
- **Migration:** absorbed into `Slate\Services\Media` (shim retired last).

### shipping-flat-rate — `1.0.2` · I  &  flat-rate-shipping — `1.0.0` · I
- **Purpose:** two shipping strategies — per-box (USPS presets, bin-packing) and
  per-weight tiers.
- **Tables:** `shippingflatrate_boxes` / `flatrateshipping_tiers` *(prefix ≠ slug)*.
  Both `DECIMAL`.
- **Permissions:** `shipping_flat_rate.manage` / `shipping.manage`.
- **Problems:** **both register `shop_shipping_rate` — activate at most one** (a
  dashboard conflict notice exists).
- **Migration:** → one `ShippingRateProvider` capability, single active (Phase 3).

---

## Cross-plugin dependency graph (as-built, API-level)

```
shop ─┬─▶ stripe-payment ─(reverse)─▶ shop      (bidirectional — to decouple)
      ├─▶ shipping-flat-rate | flat-rate-shipping
      └─▶ content-builder (BlockRegistry)
booking ──▶ stripe-payment
booking-plus ──▶ booking (BookingAPI)
membership ──▶ booking, stripe-payment
coaching ──▶ membership, booking, booking-plus
restaurant / clientdesk ──▶ stripe-payment
seo / small-business-kit ──▶ content-builder
shop / others ──▶ media-library (picker)
```
All edges are `class_exists('<X>API')` guards (graceful degradation). Only shop↔
stripe-payment is bidirectional. No plugin depends on another's **tables**.

## Common problems (recurring)

1. Duplicate person tables (shop/booking/restaurant/clientdesk) — **Phase 2**.
2. `class_exists` global-class coupling — **Phase 3** (contracts).
3. `DECIMAL` vs cents (shop + shipping + survey-pipeline) — **Phase 3** (Money).
4. Prefix ≠ slug (6 plugins) — cosmetic; fixed as modules are rebuilt.
5. `ensureSchema()` on hot path (esp. membership widget) — **Phase 1** (migrations).

---

## Related

- [database-as-built.md](database-as-built.md) · [runtime-catalogues.md](runtime-catalogues.md) · [architecture-mapping.md](architecture-mapping.md) · [compatibility-matrix.md](compatibility-matrix.md)
