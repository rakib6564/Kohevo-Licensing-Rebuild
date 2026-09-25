# Current Implementation — Runtime Catalogues

**Status:** Living reference · **Describes:** today's runtime · hooks **verified**
by extraction from the code.

The authoritative lists of what fires, what routes exist, and what runs on cron in
the **current** system. These are today's global-string hooks (not the target
typed events — see [../06-SDK/event-catalogue.md](../06-SDK/event-catalogue.md) for
the destination). Preserving these names + payloads is a
[load-bearing behavior](load-bearing-behaviors.md).

---

## 1. Hook catalogue (43 hooks — verified)

Fired via `Hook::doAction` / `Hook::applyFilters`; consumed via
`Hook::addAction` / `Hook::addFilter`. Global string names, no namespacing.

### Admin shell
| Hook | Kind | Purpose |
|---|---|---|
| `admin_nav_items` | filter | contribute sidebar nav entries |
| `admin_dashboard_widgets` | filter | contribute dashboard widgets |
| `admin_topbar_actions` | filter | topbar action buttons |
| `admin_topbar_search` | filter | topbar search sources |
| `admin_head` | action | inject into admin `<head>` |

### Customer portal
| Hook | Kind | Purpose |
|---|---|---|
| `customer_nav_items` | filter | portal nav entries |
| `customer_dashboard_widgets` / `customer_dashboard_kpis` | filter | portal dashboard content |
| `customer_head` | action | portal `<head>` injection |
| `customer_registered` / `customer_logged_in` / `customer_email_verified` | action | portal auth lifecycle |

### Auth
| `user_logged_in` / `user_logged_out` | action | admin auth lifecycle |

### Routing & public
| `public_routes` | filter | register public URL prefixes |

### Cron
| `frequent_cron` / `daily_cron` | action | scheduled work (see §3) |

### i18n
| `i18n_lang_paths` / `i18n_supported_languages` | filter | language file paths + locale list |

### Media
| `media_usage` | filter | report derived/legacy media references |

### Payments (Stripe)
| `stripe_webhook_event` | action | dispatched per verified Stripe webhook (bridged to notifications in `config.php`) |

### Shop
| Hook | Kind | Purpose |
|---|---|---|
| `shop_shipping_rate` | filter | contribute a shipping rate (⚠︎ two plugins register this — run one) |
| `shop_cart_shipping_breakdown` | filter | shipping line breakdown |
| `shop_payment_providers` | filter | register a checkout payment provider |
| `shop_order_created` / `shop_order_status_changed` | action | order lifecycle |
| `shop_product_db_row` | filter | augment a product row |
| `shop_product_edit_shipping_fields` | action | product-editor shipping fields |

### Booking
| Hook | Kind | Purpose |
|---|---|---|
| `booking_can_book` / `booking_slot_allowed` | filter | gate a booking (membership integrates here) |
| `booking_created` / `booking_paid` / `booking_cancelled` / `booking_rescheduled` / `booking_status_changed` | action | appointment lifecycle |
| `booking_reminder_body` | filter | customize reminder content |

### Content Builder
| Hook | Kind | Purpose |
|---|---|---|
| `content_register_blocks` | action/filter | register block types (order-independent fallback) |
| `content_register_patterns` | action | register block patterns |
| `content_edit_sidebar` | action | post-editor sidebar |
| `content_head_tags` | filter | inject `<head>` tags (SEO uses this) |
| `content_footer` | action | inject footer (SBK chrome uses this) |
| `content_save_post` | action | post-save side effects |

### Forms
| `forms_submitted` | action | fired on a form submission |

> **Preservation:** every name + argument count above is relied on by installed
> plugins. In the target, these are wrapped by a typed `EventBus`
> ([architecture-mapping.md](architecture-mapping.md)) — the strings keep firing.

## 2. Route catalogue

### Admin (`/admin/…`) — direct PHP entry points (no front controller)
`index` (dashboard), `login`, `logout`, `plugins`, `users`, `roles`, `settings`,
`audit`, `media`, `notifications`, `contact_forms` (legacy), `help`, `diag`,
`oauth_callback`, `repair-settings`, `opcache-reset`. Each `require`s `config.php`
then `Auth::require()` + `Auth::requirePerm(...)`. Plugins add admin pages under
`plugins/<slug>/admin/*.php` (counts in [modules-as-built.md](modules-as-built.md)),
surfaced via the `admin_nav_items` filter.

### Customer portal (`/customer/…`)
`register`, `verify`, `login`, `logout`, `forgot`, `reset`, `resend`, `index`
(dashboard). Plugins add portal surfaces via `customer_nav_items`.

### Public (via the `public_routes` filter → `PublicRouter` → `public.php`)
| Prefix | Plugin | Notes |
|---|---|---|
| `/book` | booking | multi-step widget; `/book/done` payment confirm |
| `/forms/<slug>` | forms | + iframe embed |
| `/p/<slug>`, `/<type>/<slug>` | content-builder | pages + custom post types (draft preview) |
| storefront (index/category/product/cart/checkout/order) | shop | registered via filter |
| webhook / return / success / create-intent | stripe-payment | Stripe completion endpoints |

`route.php` is a rewrite **trampoline** keeping `/includes/` 403 under a subdir
deploy. Exact prefixes are declared in each plugin's `public_routes` handler
(format varies per plugin).

### API
**No first-class REST API exists today.** The only HTTP-API-like surfaces are the
Stripe webhook/return endpoints and Forms webhooks (outbound). The versioned
`/api/v1` in [../07-API](../07-API/) is a **target**, not current.

## 3. Cron / scheduler (as-built)

- Entry point: **`cron.php`**, gated by `CRON_SECRET` (query param/header).
- Fires two actions: **`frequent_cron`** (reminders, webhook retries, follow-ups)
  and **`daily_cron`** (daily digests/cleanup). Plugins subscribe in `boot()`.
- **No persistent worker / no queue** — all scheduled work drains synchronously on
  the cron tick. (The target introduces a `Queue` — [../13-Operations](../13-Operations/).)
- Client setup: an external cron hits
  `https://…/slate/cron.php?key=<CRON_SECRET>` on a schedule.

## 4. Permission keys (as-built, per plugin)

Core: `users.*`, `customers.*`, `contact.*`, `settings.*`, `audit.*`, `media.*`.
Plugin permission keys (from manifests, `<domain>.<action>`):

| Plugin | Keys |
|---|---|
| booking | view, manage_services, manage_providers, manage_appointments, manage_resources, manage_settings, manage_payments |
| booking-plus | bookingplus.manage_settings, reply_messages |
| shop | view_orders, manage_orders, manage_products, manage_coupons, view_reports, manage_settings |
| shop-emails | shop_emails.manage |
| stripe-payment | stripe.manage_settings |
| membership | view, manage_plans, manage_members, manage_settings |
| coaching | view_clients, manage_clients, reply_chat, manage_library |
| clientdesk | view, manage_clients, manage_projects, manage_quotes, manage_invoices, manage_team, handle_support |
| restaurant | view, pos, manage_menu, manage_floor, manage_orders, manage_customers, manage_settings, reports |
| forms | view, manage, export |
| timeclock | view, manage |
| survey-pipeline | view, manage, admin |
| sitehub | view, manage |
| flat-rate-shipping | shipping.manage · shipping-flat-rate | shipping_flat_rate.manage |
| small-business-kit | sbk.theme |

**Note:** `content-builder`, `media-library`, and `seo` declare **no** permission
keys in their manifests (they gate via core `media.*`/`settings.*` or are
open) — verify before relying on a content-builder-specific permission.

Permissions surface via `Auth::knownPermissions()` (union of core + active-plugin
manifests). **The `activate()` registration loop is dead** — see
[gotchas §3](gotchas-and-preservation-notes.md).

---

## Related

- [gotchas-and-preservation-notes.md](gotchas-and-preservation-notes.md) · [modules-as-built.md](modules-as-built.md) · [plugin-system-as-built.md](plugin-system-as-built.md)
- Target: [../06-SDK/event-catalogue.md](../06-SDK/event-catalogue.md) · [../07-API](../07-API/)
