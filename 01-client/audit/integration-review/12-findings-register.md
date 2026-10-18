# Findings register (2026-10-17)

One row per finding, across the security work, licensing, test infrastructure and the fresh Studio Builder and settings audits.

- **Status values:**
  - **Fixed** means a commit on a branch listed here, with a test that fails without it.
  - **Open** means nothing has been changed yet.
  - **Decision** means it is waiting on the owner.
- **Evidence:** "verified" means I re-read the cited source myself. Audit findings marked "audit" come from the read-only reports in `studio/` and `settings/`. Each report has a "Not verified" section.
- **Severity** is the author's assessment of impact if exploited or hit in normal use.

## Security and secrets (PR #102, PR 3)

| ID | Severity | Finding | Status | Where fixed / evidence |
|---|---|---|---|---|
| S-01 | High | SMTP OAuth client secret and tokens stored in plaintext | Fixed | PR 3 `b8a6fdc`; `SettingsEncryptFirstTest` |
| S-02 | High | Multilang translation API key stored in plaintext | Fixed | PR #102 `6fed518` |
| S-03 | High | AI base URL accepted private/loopback hosts (SSRF with the stored key) | Fixed | PR #102; public-https check on save and on use |
| S-04 | High | Stripe keys: plaintext fallback when encryption failed | Fixed | PR 3; fail closed, `SettingsEncryptFirstTest` |
| S-05 | Medium | Forms CAPTCHA secret stored in plaintext in `settings_json` | Fixed | PR 3; legacy values read, re-sealed on save |
| S-06 | Medium | Booking and Backups Google tokens: plaintext on refresh | Fixed | PR 3 |
| S-07 | Medium | Twilio auth token: blank submit erased the stored value | Fixed | PR #102; blank keeps, explicit Clear removes only that secret |
| S-08 | Medium | Settings forms saved other fields when sealing a secret failed (partial save) | Fixed | PR 3 `slate_seal_posted_secrets`; a missing `APP_SECRET` saves nothing and says so |
| S-09 | Medium | No safe way to rotate `APP_SECRET` | Fixed | PR 3 `c56c7be` `bin/rekey-secrets.php`; dry run, one transaction, ciphertext backup 0600, `--restore`, non-InnoDB refused; `RekeySecretsCliTest` 7 cases |
| S-10 | High | `APP_ENV=testing` bypassed the module and licence guards on web requests | Fixed | client `8db616f`, central `d77b076`; built-in server probes, `AppEnvTestingBypassTest`, `CentralModuleGuardTest` |
| S-11 | High | MCP `settings.set` tool blocks only keys matching `pass\|secret`; can repoint `smtp_host` / `mcp-gateway.ai_base_url` (sends stored credentials elsewhere) and overwrite `*_token`/`*_api_key` in plaintext | **Fixed** (N2) | `fix/mcp-settings-allowlist` `3862f1c`: allow-list of 13 general settings with page validators; reads hide credentials and `enc:v1` values; audit redaction widened; `McpSettingsAllowlistTest` 5/tree |
| S-12 | Medium | `membership.terms_url` unvalidated, printed as a link (`javascript:` possible) | Open | audit; `membership/admin/settings.php:32`, `onboarding.php:99-103` |
| S-13 | Medium | Multilang switcher colours unvalidated, written into an inline style | Open | audit; `multilang-translate/admin/api.php:176-185` |
| S-14 | Low | `bookingplus.whatsapp_url` has no server-side URL check | Open | audit; `BookingPlusAPI.php:269` |

## Licensing and module access (PR 2)

| ID | Severity | Finding | Status | Where fixed / evidence |
|---|---|---|---|---|
| L-01 | High | Central Forms/Booking/Membership fatal: `ModuleGuard` class missing | Fixed | PR 2 `08f3b99` |
| L-02 | High | No way to assign a plan to a tenant; per-plan gating would lock tenants out | Fixed | PR 2 `2866452` Tenants › Plan selector (platform admin, CSRF, audit `tenant.plan_assigned`, inactive plans refused) + `PlanCoverage` warning + CLI; `TenantPlanAdminTest` |
| L-03 | — | D1: gate central modules per plan (recommended) vs exempt central | **Decision** | `09-d1-decision-and-moduleguard-review.md` |
| L-04 | — | Retired (inactive) plan: tenants already on it keep its modules; new assignment refused | **Decision** (implemented as recommended, test documents it) | `TenantPlanAdminTest` case 3 |
| L-05 | Medium | Dashboard "Platform overview" and "Your plan" sections removed by `0d48565` (likely accidental); 2 `DashboardMetricsTest` failures | **Decision** (restore or retire) | `02-licensing/admin/index.php` |

## Test infrastructure

| ID | Finding | Status | Where fixed |
|---|---|---|---|
| T-01 | Licensing single-process run crashed (`Cannot redeclare licplug_teardown()`) | Fixed | `c926c18` shared helper |
| T-02 | `LicenseGateTest` seeded licence-cache rows without `installation_id` (obsolete since Phase 13 trust rule) | Fixed | `5462c1e`; 9/9, new case for untrusted rows |
| T-03 | `SettingsEncryptFirstTest` left `booking.cap.*` changed | Fixed | `240caef` |
| T-04 | Process-wide Enterprise test plan leaked `white_label` | Fixed | `fd592d3` |
| T-05 | `TenantSwitchAuditTest` re-required `TenantOverrideRecursionTest.php`; fatal in any non-alphabetical order (both trees) | Fixed | `19c9a55`; found by seeded shuffled runs |
| T-06 | `MfaRepositoryTest` left the admin session `completeMfa()` registers; a later test hit the unique key in shuffled order (both trees) | Fixed | `444c531` |
| T-07 | Skip-as-pass guards in PR #102's `SecretHandlingGapsTest` (2) | Open (Low) | not taken while `APP_SECRET` is set |
| T-08 | Studio Playwright e2e not run locally (its sandbox drops `slate_sbx_e2e`) | Open | runs in CI on push |
| T-09 | `Phase10ZAutoRefreshTest` used helpers defined in `Phase10SynchronizationTest` and relied on loading after it; failed alone or in other orders | Fixed | `234b4d6` (`tests/support/phase10.php`); a scan of every integration file in both trees found no other cross-file helper use |

## Studio Builder: widgets and controls (60 block types, 333 control rows)

| ID | Severity | Finding | Status | Evidence |
|---|---|---|---|---|
| W-F1 | **High** | Stored XSS: `theme.post_content` "Fallback Content" is a plain text field whose filter misses event-handler attributes (`<img src=x onerror=…>` passes); the renderer outputs any value containing a tag unescaped. Studio has no raw-HTML block, so this lets an editor run script on public pages and in admin previews | **Fixed** (N1) | `fix/studio-post-content-xss` `c19c9d5`: markup goes through `RichTextSanitizer`; new Sprint6 case, 6 payloads |
| W-F2 | High | Border styles "Solid", "Dotted", "Double" have no CSS | Open | audit; `StyleControls.jsx:475-484`, `StudioStylesheet.php:243` |
| W-F3 | High | Query Loop pager is hard-coded (always page 1, link to `?page=2`), on by default | Open | audit; `QueryLoopRenderer.php:155-162` |
| W-F4 | Medium | Per-device alignment unscoped (same root cause as CL-03) | Open | **verified** |
| W-F5…F7 | Medium | Off-canvas top/bottom, tabs "bottom" and the "scroll" interaction trigger have no CSS/runtime | Open | audit |
| W-F8, F9, F10 | Medium | Block field labels not translated; runtime replaces translated carousel labels with English; hard-coded English in public output | Open | audit |
| W-F11 | Medium | `theme.post_title` / `post_content` "Fallback" overrides live post data | Open | audit |
| W-F12, F13 | Medium | Two hide mechanisms with different breakpoints; duplicate flex/grid controls (same as CL-02) | Open | audit |
| W-F14 | Medium | `payment.button` cached as static; pages compiled before Stripe setup stay without a button up to an hour | Open | audit; `PaymentButtonRenderer.php:29,41` |
| W-F15, F16 | Medium | Search input has no label and posts to a hard-coded `/search`; carousel tablist semantics, autoplay not pausable on touch | Open | audit |
| W-F18, F28 | Medium | `portfolio.rakib_showcase` untested, unvalidated `href`/`mailto:`; `portfolio.footer` hard-codes the owner's contact address and generic social links | Open | audit |
| W-Low | Low | Non-deterministic `core.form_field` id; accepted-but-unrendered style values; drifted duplicate `WidgetRegistry` | Open | audit; `studio/studio-widgets-controls.md` |

## Studio Builder: Theme Builder and templates

| ID | Severity | Finding | Status | Evidence |
|---|---|---|---|---|
| TB-01 | High | Draft display conditions and `template_type` take effect live without publishing (resolver reads `pages.settings_json`, rewritten by every draft save, rollback, import and `applyTemplate`) | **Fixed** (N4) | `fix/studio-theme-published-conditions` `72fd527`: candidates scored from published settings; new Sprint6 case |
| TB-02 | High | Template delete finds the backing page with `LIKE '%"template_id":5%'` (also matches 50, 512 …) and hard-deletes it with its revisions | **Fixed** (N3) | `fix/studio-template-delete` `885d23e`: exact backing-page match, archived via service (revisions kept), unknown/system refused; `StudioTemplateDeleteTest` |
| TB-03 | High | `admin/templates.php` bypasses Studio permission levels (unpublish via raw SQL with edit permission, no audit; create/delete need only edit) | **Fixed** (N5) | `fix/studio-admin-permissions` `4aa8550`: per-action Studio permission (create/delete admin, publish/unpublish publish); unpublish only Theme Builder pages, audited, cache dropped; `StudioAdminPermissionsTest` |
| TB-04 | Medium | Custom 404 templates never served | Open | audit; `PublicRouter.php:92-96` |
| TB-05 | Medium | Live search template always gets an empty query | Open | audit; `public.php:30-35` |
| TB-06 | Medium | Several condition types never match or match the wrong page (`author`, `front_page`, "all posts") | Open | audit |
| TB-07 | Medium (latent) | Search box would cache the first query for every visitor once TB-05 is fixed | Open | audit; `SearchBoxRenderer.php:21-23` |
| TB-08 | Medium | "Single" templates never applied, yet published as public pages and listed in the sitemap | Open | audit |
| TB-09 | Medium | Candidate queries `LIMIT` before filtering; templates silently drop out on larger sites | Open | audit; `PageRepository.php:56-65` |
| TI-04 | Medium | `applyTemplate` copies the template's conditions and type onto the target page | Open | audit; `StudioTemplateService.php:312-314` |
| TB-10…16, TI-05…07 | Low/Info | Ties decided by title, exclusion typos, archive term length, preview vs live, no package migration path | Open | audit |
| TI-OK | — | Import/export: no finding (strict envelope, CSRF, size/depth caps, ids re-minted, no URL fetch, no code execution path) | — | audit |

## Studio Builder: containers, layout and design tokens

| ID | Severity | Finding | Status | Evidence |
|---|---|---|---|---|
| CL-01 | High | Page "Width" setting does nothing: no `.sb-main--*` CSS outside a seed-only stylesheet | Open | **verified** (`StudioStylesheet.php:52`; only `bin/seed-rakib-portfolio.php` references `portfolio_style.css`) |
| CL-02 | High | Flex/grid container props and `style.layout` edit the same values; `style.layout` silently wins | Open | audit; `FlexRenderer.php:40-77`, `StyleSurface.php:55-70` |
| CL-03 | High | Per-device alignment classes have no media queries; the mobile value applies at every width | Open | **verified** `StudioStylesheet.php:245-247` |
| CL-04 | High | Width/height/min/max sizes are inline and cannot vary per device | Open | audit; `DocumentRenderer.php:619-626` |
| DT-01 | High | Mobile Theme sheet edits refs the server rejects, so it can never save | Open | audit; `ThemeBottomSheet.jsx`, `StudioThemeService.php:140-147` |
| DT-02 | High | Branding layer mostly dead: four `TenantBranding` keys have no writer; Branding fonts/corners reach only admin screens | Open | audit; `TenantBranding.php:36-42` |
| CL/DT Medium | Medium | Grid columns not responsive by default; hover states lose to inline colours; preset radius/shadow ignore tokens; "md" spacing means three sizes; button contrast; two undefined CSS variables; canvas drop only into `layout.*` | Open | audit; full list in `studio/studio-containers-layout.md` §7 and `studio/studio-design-tokens.md` §5 |

## Settings

| ID | Severity | Finding | Status | Evidence |
|---|---|---|---|---|
| ST-01 | High | Studio Content page has no permission check beyond login; any admin can rewrite the public portfolio content | **Fixed** (N5) | `fix/studio-admin-permissions` `4aa8550`: `content.php` requires `studio-builder.edit` |
| ST-02 | Medium | Settings saved but never read (Media incl. max upload size, Permalinks, Debug mode, Studio SEO/Redirects/AI & MCP fields) | Open | audit |
| ST-03 | Medium | Booking admin notification emails never sent (`booking.notify_admin_email` / `site_admin_email` have no writer) | Open | **verified**: only reads (`Booking.php:1010`, `public/message.php:192`) |
| ST-04 | Medium | `booking.cap.*` written by two screens with different permissions | Open | audit |
| ST-05 | Medium | 24 client / 22 central keys read but never written (defaults always apply) | Open | audit; `settings/settings-full-inventory.csv` |
| ST-06 | Info | Existing 100-row inventory covers 54 of 251 client keys; wrong rows listed | Superseded | `settings/settings-full-inventory.csv` (482 rows) |
