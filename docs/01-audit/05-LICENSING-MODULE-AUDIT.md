# Phase 0: 05 — Licensing Module Audit

**Document Status:** Complete Fact-Based Audit  
**Phase:** Phase 0 — Existing System Audit  
**Target Architecture Reference:** `planning.md`, `docs/00-project/REQUIREMENTS.md` (§3), `docs/00-project/DECISIONS.md` (§4, §5, §6)  
**Sources Inspected:**
1. Core: `01-client/admin/users.php`, `roles.php`, `index.php`, `settings.php`
2. Form Builder: `01-client/plugins/forms/`
3. Membership: `01-client/plugins/membership/`
4. Booking: `01-client/plugins/booking/`
5. Excluded Modules: `01-client/admin/editor.php`, `posts.php`, `src/Services/Content/`

---

## 1. Executive Summary

This audit evaluates the architectural implementation, registration mechanisms, lifecycle management, route/API boundaries, and existing entitlement enforcement across all V1 Core modules, V1 Optional modules, and V1 Excluded modules:

1. **Core Modules Lack Commercial Gate:** Core features (Admin/User, Dashboard, Site Settings) are embedded into the base application. They rely solely on user authentication and RBAC permissions. They lack any check against global license validity.
2. **Optional Modules Possess Zero Entitlement Checks:** The three optional modules (`forms`, `membership`, `booking`) are implemented as Slate plugins. In their admin controllers, public routes, API endpoints, customer portals, and background jobs, **there is not a single call to `EntitlementService` or license verification**. Any active plugin is 100% accessible to any user with the appropriate RBAC permission.
3. **No Optional Module Interdependencies:** Confirming REQUIREMENTS.md §3.2, there are **no functional dependencies between Form Builder, Membership, and Booking**. Each functions completely independently and can be enabled or disabled in any combination.
4. **V1-Excluded Modules Identified:** `Editor` and `Content` are verified as existing core features of the Slate platform. They are correctly identified as excluded from V1 commercial licensing scope.

---

## 2. V1 Core Modules Audit

Core features must be automatically included in every valid license and cannot be disabled or individually selected.

### 2.1 Admin / User Management
- **Files:** `01-client/admin/users.php`, `01-client/admin/roles.php`, `01-client/src/Services/Auth/Auth.php`.
- **Registration:** Core functionality built into the application shell. Not a plugin.
- **Enabled / Disabled:** Always enabled. Cannot be disabled.
- **Routes & Entry Points:**
  - `GET /admin/users.php`: List and filter users.
  - `POST /admin/users.php`: Create/edit user, reset passwords, toggle user status.
  - `GET /admin/roles.php`: Role listing and permission assignment.
- **Permissions:** `users.view`, `users.manage`, `roles.view`, `roles.manage`.
- **Existing Licensing Checks:** **NONE**. Does not query license validity.
- **Dependencies:** Core database tables (`users`, `roles`, `role_permissions`, `admin_sessions`).

### 2.2 Dashboard
- **File:** `01-client/admin/index.php`.
- **Registration:** Core application landing page post-authentication.
- **Enabled / Disabled:** Always enabled.
- **Routes & Entry Points:** `GET /admin/index.php`.
- **Permissions:** Requires `Auth::require()`. Any authenticated user can view.
- **Existing Licensing Checks:** **PARTIAL / PRESENTATIONAL ONLY**.
  - Lines 355-388 query `EntitlementService::authorityMode()` and `EntitlementService::enabledFeaturesFor()` solely to render KPI stat cards (Plan name, License status, Expiry, Enabled features count).
  - It does **not** block access if the license is invalid, expired, or absent.
- **Dependencies:** `Database`, `Auth`, `Hook` (`admin_dashboard_widgets`).

### 2.3 Site Settings
- **File:** `01-client/admin/settings.php`.
- **Registration:** Core settings controller supporting multi-tab configuration (`general`, `branding`, `datetime`, `smtp`, `security`, `landing`, `system`).
- **Enabled / Disabled:** Always enabled.
- **Routes & Entry Points:** `GET/POST /admin/settings.php?tab=<tab_slug>`.
- **Permissions:** `settings.view`, `settings.edit`.
- **Existing Licensing Checks:** **NONE**. Does not query license status or entitlements.
- **Dependencies:** `settings` database table, `slate_encrypt_secret()`.

---

## 3. V1 Optional Modules Audit

Each optional module must be independently selectable per license.

### 3.1 Form Builder (`plugins/forms`)
- **Plugin Manifest:** `01-client/plugins/forms/plugin.json` (Slug: `forms`, Name: `Forms`, Version: `1.0.0`).
- **Registration & Bootstrap:**
  - Subclasses `Plugin` in `Forms.php`.
  - Bootstrapped via `PluginLoader::boot()` if `plugins.status = 'active'`.
  - Hooks: `admin_nav_items`, `admin_dashboard_widgets`, `public_routes`, `content_head_tags`, `content_register_blocks`.
- **Enabled / Disabled:** Controlled strictly by `plugins.status` in the `plugins` table.
- **Routes & Entry Points:**
  - **Admin:** `admin/index.php`, `admin/form_edit.php`, `admin/submissions.php`, `admin/contacts.php`, `admin/spam_log.php`, `admin/webhooks.php`.
  - **Public:** `/forms/<slug>` dispatched via `public/router.php`.
  - **API:** MCP handler (`FormsMcpHandler.php`).
- **Permissions Checked:** `forms.view`, `forms.manage`.
- **Existing Licensing Checks:** **ZERO**. Neither `Forms.php`, `FormsAPI.php`, nor any file in `admin/` or `public/` checks `EntitlementService` or license status.
- **Dependencies on Other Modules:** None. (Optional hook integration with Content Builder block registry if Content is active, but functions completely without it).

### 3.2 Membership (`plugins/membership`)
- **Plugin Manifest:** `01-client/plugins/membership/plugin.json` (Slug: `membership`, Name: `Membership`, Version: `1.0.0`).
- **Registration & Bootstrap:**
  - Subclasses `Plugin` in `Membership.php`.
  - Hooks: `admin_nav_items`, `admin_dashboard_widgets`, `customer_nav_items`, `customer_portal_activity`, `public_routes`, `frequent_cron`.
- **Enabled / Disabled:** Controlled strictly by `plugins.status` in the `plugins` table.
- **Routes & Entry Points:**
  - **Admin:** `admin/index.php`, `admin/members.php`, `admin/plans.php`, `admin/subscriptions.php`, `admin/perks.php`, `admin/analytics.php`, `admin/settings.php`.
  - **Customer Portal:** `/member/membership/*` routed via `customer/portal_router.php` and `plugins/membership/public/router.php`.
  - **Public:** `/member?view=plans`, `/member?view=onboarding`.
- **Permissions Checked:** `membership.view`, `membership.manage`.
- **Existing Licensing Checks:** **ZERO**. Completely ungated by licensing or entitlements.
- **Dependencies on Other Modules:** None. Operates its own member profiles and subscription tables (`membership_plans`, `membership_subscriptions`, `membership_profiles`).

### 3.3 Booking (`plugins/booking`)
- **Plugin Manifest:** `01-client/plugins/booking/plugin.json` (Slug: `booking`, Name: `Booking`, Version: `1.0.0`).
- **Registration & Bootstrap:**
  - Subclasses `Plugin` in `Booking.php`.
  - Hooks: `admin_nav_items`, `admin_dashboard_widgets`, `customer_nav_items`, `customer_dashboard_widgets`, `public_routes`, `frequent_cron`, `stripe_webhook_event`, `api_v1_routes`.
- **Enabled / Disabled:** Controlled strictly by `plugins.status` in the `plugins` table.
- **Routes & Entry Points:**
  - **Admin:** `admin/index.php`, `admin/calendar.php`, `admin/appointments.php`, `admin/services.php`, `admin/providers.php`, `admin/schedules.php`, `admin/customers.php`, `admin/settings.php`.
  - **Public:** `/book`, `/book/*` routed via `public/router.php` and `customer/book.php`.
  - **API:** Registered on `/api/v1/booking` via `ApiRouter`.
  - **Background / Cron:** `sendReminders()` runs on `frequent_cron` to dispatch email reminders.
- **Permissions Checked:** `booking.view`, `booking.manage`.
- **Existing Licensing Checks:** **ZERO**. No check against licensing or entitlements on any admin page, public booking page, API route, or cron sweep.
- **Dependencies on Other Modules:** None.

---

## 4. V1 Excluded Modules Audit

REQUIREMENTS.md §3.4 and DECISIONS.md §6 explicitly state that `Editor` and `Content` are **excluded from V1 licensing scope**. They must not be added to V1 license creation, entitlement enforcement, or client licensing UI.

### 4.1 Editor
- **Files:** `01-client/admin/editor.php`, `01-client/admin/editor-preview.php`.
- **Implementation:** Monolithic visual block-based page editor ("Phase 2 DocumentSchema / Structured React Builder").
- **Current State:** Direct admin file. Requires `Auth::require()`. Checks `editor.view` / `editor.manage` permissions.
- **Verification:** Not an independent plugin; built directly into `admin/`. Confirmed excluded from V1.

### 4.2 Content
- **Files:** `01-client/admin/posts.php`, `admin/post-edit.php`, `admin/templates.php`, `src/Services/Content/` (`PlatformIdentityPolicy.php`, `PlatformSignature.php`, `PublicContentRoute.php`).
- **Database Tables:** `content_pages`, `content_compilation_artifacts`, `content_revisions`, `document_templates`.
- **Current State:** Core content engine for rendering static pages and blog posts.
- **Verification:** Built directly into the core runtime. Confirmed excluded from V1.

---

## 5. Summary Findings Classification

| ID | Finding Description | Evidence / Code Location | Classification | Impact | Related Requirement |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **MOD-01** | Core modules completely ungated by license status | `01-client/admin/index.php`, `users.php`, `settings.php` | CONFLICTS WITH TARGET | Unlicensed installations can freely use all Core modules | REQ §3.1, §5, DEC §4 |
| **MOD-02** | Form Builder lacks server-side entitlement checks | `01-client/plugins/forms/Forms.php` lines 64-100 | CONFLICTS WITH TARGET | Unentitled clients can access forms via admin, public, and API | REQ §3.2, §5, DEC §7 |
| **MOD-03** | Membership lacks server-side entitlement checks | `01-client/plugins/membership/admin/index.php` lines 11-14 | CONFLICTS WITH TARGET | Unentitled clients can access membership via admin and portal | REQ §3.2, §5, DEC §7 |
| **MOD-04** | Booking lacks server-side entitlement checks | `01-client/plugins/booking/admin/appointments.php` lines 8-10 | CONFLICTS WITH TARGET | Unentitled clients can access booking via admin, public, and API | REQ §3.2, §5, DEC §7 |
| **MOD-05** | Booking background cron sweeps run without entitlement check | `01-client/plugins/booking/Booking.php` lines 52, 450-520 | CONFLICTS WITH TARGET | Reminder emails continue sending for unentitled/expired licenses | REQ §5 |
| **MOD-06** | Optional modules have zero interdependencies | `plugins/forms`, `plugins/membership`, `plugins/booking` | EXISTING | Validates requirement that optional modules are completely decoupled | REQ §3.2, DEC §5 |
| **MOD-07** | Editor and Content verified as distinct core components | `01-client/admin/editor.php`, `posts.php` | EXISTING | Can remain cleanly separated outside V1 licensing scope | REQ §3.4, DEC §6 |
