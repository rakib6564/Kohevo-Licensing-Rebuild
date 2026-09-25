# Current Implementation — Database (As-Built)

**Status:** Living reference · **Describes:** the schema as it exists today ·
**Verified** against `db/schema.sql` and every `plugins/*/install.sql`.

The complete current database: all tables, who owns them, relationships, tenant
handling, and the migration risks that Phase 1–3 must navigate. This is the
highest-risk surface of the refactor — [ADR-0006](../14-ADR/0006-unified-identity-contacts.md)
(identity) and [ADR-0010](../14-ADR/0010-migrations-over-ensureschema.md)
(migrations) exist because of what's documented here.

---

## 1. Core tables (14 + 2 lazy)

Defined in `db/schema.sql`, created at install. **Every core table has a
`tenant_id`** (default 1). Engine InnoDB, `utf8mb4_unicode_ci`.

| Table | Purpose | Key relationships |
|---|---|---|
| `tenants` | tenant registry | — (root of tenancy) |
| `roles` | named roles (Super Admin = id 1) | — |
| `role_permissions` | `<domain>.<action>` grants | **FK → `roles`** (ON DELETE CASCADE) |
| `users` | admin/staff logins | **FK → `roles`** (ON DELETE RESTRICT) |
| `customers` | portal end-users | UNIQUE `(tenant_id,email)`; **no FK from plugin person tables** (§4) |
| `customer_auth_tokens` | hashed verify/reset tokens | indexed by `customer_id`,`purpose` (no FK) |
| `settings` | key/value config | UNIQUE `(tenant_id,setting_key)` |
| `plugins` | install registry (source of truth for boot) | UNIQUE `slug`; holds `manifest_json` |
| `audit_log` | actor/action/target trail | indexed `(tenant_id,user_id,created_at)` |
| `contact_forms` | **legacy** core forms | — |
| `contact_form_submissions` | legacy submissions | **FK → `contact_forms`** (CASCADE) |
| `lang_overrides` | per-locale string overrides | UNIQUE `(tenant_id,locale,lang_key)` |
| `media_files` | core media library | UNIQUE `(tenant_id,path)` |
| `media_usage` | media reference tracking | **FK → `media_files`** (CASCADE) |

**Lazy/self-installed** (not in `schema.sql`; created on first use):
`slate_notifications` (topbar bell), `login_attempts` (throttling). `media_files`
/`media_usage` are also reconciled by `Media::ensureSchema()` on each non-CLI boot.

**Relationships are sparse:** only 4 declared foreign keys exist
(`role_permissions→roles`, `users→roles`, `contact_form_submissions→contact_forms`,
`media_usage→media_files`). Most relationships are **by-convention id columns with
no enforced FK** — a key migration risk (§4).

## 2. Plugin tables (by owner — verified)

Each plugin owns tables under **its own prefix**. **Verified:** no plugin creates,
alters, drops, or directly queries another plugin's tables (see
[gotchas §3](gotchas-and-preservation-notes.md)). Prefix ≠ slug where noted.

| Plugin (slug) | Prefix | Tables |
|---|---|---|
| booking | `booking_` | services, providers, provider_services, provider_hours, appointments, customers, categories, locations, resources, service_resources, service_addons, custom_fields, provider_breaks, date_overrides (14) |
| booking-plus | `bookingplus_` | service_config, slot_restrictions, appointment_meta (3) |
| shop | `shop_` | products, customers, orders, order_items, coupons (5) |
| stripe-payment | `stripepayment_` ⚠︎ | sessions, charges (2) |
| membership | `membership_` | plans, subscriptions, profiles, wallet, wallet_txns (5) |
| coaching | `coaching_` | profile, goal, goal_checkin, extra_action, diary_entry, diary_food, diary_photo, hydration, activity, thread, message, meal_structure, shopping_list, recipe, challenge, summary (16) |
| clientdesk | `clientdesk_` | clients, projects, milestones, activity, intake, assignments, invoices, tickets, ticket_messages, quotes, files, comments, templates, access_requests (14) |
| restaurant | `restaurant_` | menu_categories, items, modifier_groups, modifiers, item_modifier_groups, sections, tables, customers, orders, order_items, order_item_modifiers, payments, readers (13) |
| content-builder | `contentbuilder_` ⚠︎ | post_types, posts, post_meta, taxonomies, terms, term_relations, menus (7) |
| forms | `forms_` | definitions, submissions, webhooks, webhook_log, spam_log (5) |
| timeclock | `timeclock_` | employees, sites, tasks, active, entries (5) |
| survey-pipeline | `surveypipeline_` ⚠︎ | connections, orders, events (3) |
| sitehub | `sitehub_` | sites, runs, backups (3) |
| seo | `seo_` | **`seo_settings`** ⚠︎ (bypasses the core `settings` table) |
| media-library | `medialibrary_` ⚠︎ | files (1 — legacy; superseded by core `media_files`) |
| flat-rate-shipping | `flatrateshipping_` ⚠︎ | tiers (1) |
| shipping-flat-rate | `shippingflatrate_` ⚠︎ | boxes (1) |
| shop-emails | — | none (settings-only, keys under `shop_email.`) |
| small-business-kit | — | none (hooks only) |

⚠︎ = **prefix ≠ slug** — slug→table introspection will miss these.

## 3. Tenant strategy (as-built)

- **Shared database, `tenant_id` on every table**, single default tenant (id 1).
- **Scoping is manual per query.** There is no automatic scoping layer.
  `current_tenant_id()` resolves CLI/cron override → super-admin tenant-switch →
  `TENANT_ID`. Settings/media helpers auto-scope; **general table queries do
  not** — the author must add `AND tenant_id = ?`, and some queries (booking
  availability probes, some customer-dashboard reads) **omit it**. This is the
  latent cross-tenant leak the target [Repository](../11-Database/repository-service-pattern.md)
  closes.

## 4. Migration risks (highest-priority for the refactor)

1. **Fragmented identity (the #1 risk).** A person is modeled up to **five ways**:
   core `customers`, plus `booking_customers`, `shop_customers`,
   `restaurant_customers`, `clientdesk_clients` — email-keyed, **no enforced FK** to
   `customers`. `membership` and `coaching` correctly key off `customer_id`; the
   others carry duplicate name/email/phone. Unifying to `contacts` ([Phase 2](../09-Roadmap/refactor-roadmap.md))
   requires email-matching + dedup + a dual-write/backfill window — irreversible
   without the Phase-0 safety net.
2. **Money type split.** `shop` stores `DECIMAL(12,2)` while booking, membership,
   restaurant, clientdesk, stripe-payment use integer `*_cents`. Converting shop to
   minor units ([Phase 3](../09-Roadmap/refactor-roadmap.md)) touches products,
   orders, and any report assuming decimals.
3. **No migration framework.** Schema evolves via per-plugin `ensureSchema()`
   column-reconcile on boot (7 plugins). It **cannot rename or transform data** —
   so identity consolidation and the DECIMAL→cents change **need** the new
   migration framework ([ADR-0010](../14-ADR/0010-migrations-over-ensureschema.md)).
4. **Sparse referential integrity.** Only 4 FKs exist; most links are bare id
   columns. Migrations that move data must reconcile orphans and add the FKs the
   schema never declared.
5. **Settings drift.** `seo` bypasses `settings` with `seo_settings`; `shop-emails`
   uses a `shop_email.` prefix (≠ slug). A settings migration must handle these
   explicitly.
6. **Prefix ≠ slug** for 6 plugins (⚠︎ above) — any slug-derived table tooling
   must map, not assume.
7. **Legacy duplication:** two contact-form systems (core `contact_forms*` + Forms
   plugin `forms_*`); `medialibrary_files` superseded by core `media_files`.
   Retiring either drops tables — needs migration + sign-off.

## 5. What Phase 1 changes here

Phase 1 introduces the **migration framework** and **tenant-scoped data layer**
*additively*: `ensureSchema()` and every existing table stay untouched; the core
schema is expressed as baseline migrations (stamped applied on existing installs);
new code uses repositories. No table is renamed or moved until a Phase 2/3
migration does it with a backfill. See
[architecture-mapping.md](architecture-mapping.md) and
[compatibility-matrix.md](compatibility-matrix.md).

---

## Related

- [gotchas-and-preservation-notes.md](gotchas-and-preservation-notes.md) · [architecture-mapping.md](architecture-mapping.md)
- [../02-Domain/identity-contacts.md](../02-Domain/identity-contacts.md) · [../11-Database](../11-Database/) · [../14-ADR/0006-unified-identity-contacts.md](../14-ADR/0006-unified-identity-contacts.md)
