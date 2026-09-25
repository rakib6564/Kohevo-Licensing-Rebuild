# Current Implementation — Technical Debt, Known Issues & Developer Notes

**Status:** Living reference · **Describes:** the debt register as of the v1.0
architecture freeze.

A single, honest ledger of what's owed. Each item: severity, where it bites, and
which phase resolves it. This is the "why are we doing the refactor" list, deduped
against [gotchas](gotchas-and-preservation-notes.md) and the
[architecture-mapping](architecture-mapping.md).

---

## 1. Structural debt (drives the refactor)

| # | Debt | Impact | Resolved |
|---|---|---|---|
| S1 | **Fragmented identity** — a person in up to 5 tables, email-keyed, no FK | CRM impossible; no unified customer view | Phase 2 |
| S2 | **No namespaces / no autoloader** — 691 `require_once`, global classes | name collisions; untestable; `class_exists` coupling | Phase 1 |
| S3 | **`class_exists('*API')` coupling** between plugins | brittle, unversioned, name-based | Phase 3 (contracts) |
| S4 | **Manual per-query tenant scoping** (inconsistent) | latent cross-tenant leak | Phase 1 (Repository) |
| S5 | **`ensureSchema()` self-heal** instead of migrations | can't rename/transform; runs on hot path | Phase 1 |
| S6 | **Money DECIMAL (shop) vs cents (rest)** | rounding at shop↔Stripe boundary | Phase 3 (Money) |
| S7 | **No shared render layer** — 6 doc renderers, 5 token vocabularies, 3 admin kits | duplicated UI; theming drift | Phase 4 |
| S8 | **shop↔stripe bidirectional coupling** | gateway not consumer-agnostic | Phase 3 |
| S9 | **No boot cache** — per-request plugin.json reads + settings | linear slowdown with plugin count | Phase 1/5 |
| S10 | **No dependency/version system** (`works_better_with` advisory) | activation against missing deps | Phase 3 |

## 2. Known issues (bugs / correctness)

| # | Issue | Severity | Notes |
|---|---|---|---|
| I1 | `membership` runs `ensureSchema()` **unconditionally** on its dashboard widget | 🟡 perf | full `CREATE TABLE IF NOT EXISTS` sweep every dashboard load |
| I2 | Dead `activate()` permission-registration loop (`PluginLoader.php` ~:422–433) | 🟢 dead code | permissions come from the manifest union; don't "fix" into double-registration |
| I3 | Some booking availability / customer-dashboard queries omit `tenant_id` | 🟡 correctness | cross-tenant risk; fixed by Repository |
| I4 | Storefront checkout has no coupon field though `ShopAPI` supports it | 🟢 gap | cart vs order totals computed by two paths — unify first |
| I5 | Two contact-form systems coexist (legacy core + Forms) | 🟡 duplication | retire legacy (needs migration + sign-off) |
| I6 | Two shipping plugins both register `shop_shipping_rate` | 🟡 config | activate one; dashboard conflict notice exists |
| I7 | `medialibrary_files` superseded by core `media_files` | 🟢 legacy | shim table; retire last |
| I8 | Settings drift: `shop-emails` uses `shop_email.`; `seo` uses `seo_settings` table | 🟢 inconsistency | handle explicitly in any settings migration |
| I9 | Prefix ≠ slug for 6 plugins | 🟢 cosmetic | breaks slug→table introspection |

## 3. Production-hygiene debt

| # | Item | Action |
|---|---|---|
| H1 | Stray root debug scripts (`_append.php`, `_short.php`, `_media_list.php`, `_auditcheck.php`, `_fsc.php`, `_inspect_subs.php`, `_themetest.php`) | gate/remove before public release |
| H2 | `admin/diag.php`, `admin/opcache-reset.php`, `admin/repair-settings.php` | access-gate or remove |
| H3 | `error_log` (233 KB) checked into tree; mostly historical dev warnings | clean; ensure gitignored (done) |
| H4 | `docs.zip` export artifact at root | gitignored (done) |
| H5 | `.env` with live secrets on disk | gitignored; never ship in ZIP |
| H6 | No CI (no remote wired) | run `bash tests/run.sh` before commits; wire CI when a remote exists |

## 4. Testing debt

- Only a dependency-free **smoke suite** (`tests/smoke.php`, 19/19) exists.
- No unit/integration/functional tests for money, tenancy, auth, payments, or
  migrations — the highest-value coverage to add first
  ([../12-Testing](../12-Testing/)).
- Architectural invariants are documented but **not yet enforced by CI**
  ([../12-Testing/architecture-conformance.md](../12-Testing/architecture-conformance.md)).

## 5. Developer notes (non-obvious, keep handy)

- **Deploy under `/slate/`** — base-path aware everything; smoke-test with the
  prefix.
- **Cloudflare 7-day asset cache** + mtime `?v=` busting — test the `?v=` URL.
- **`slate_brand_accent_emit()` after `slate_ui_emit_css()`** or accent → blue.
- **Never `backdrop-filter` on `.app-panel`** — traps fixed modals.
- **No horizontal scrollbars** — content wraps; `index.php` is the responsive gold
  standard; shared editor kit `includes/record_editor.php` (`.pv-*`).
- **`media-library` is a required shim** — don't deactivate.
- **Disk quota (EDQUOT) breaks all Bash** on this host — clear caches.
- **content-builder is the block spine** — promote to core, don't reinvent
  (`BlockRegistry`+`Renderer`).
- **`sitehub` ≠ site builder** — it's a WordPress fleet control plane.

## 6. Future refactor candidates (beyond the roadmap phases)

- Promote `bin/` tooling into a proper CLI (`slate` command) once namespaced.
- Replace the flat block-array layout with Section/Template objects (Phase 4) —
  prerequisite for the visual Page Builder.
- Extract a real API layer (`/api/v1`) once services exist (enables headless/SaaS).
- Consolidate `coaching`/`clientdesk`/`restaurant`/`timeclock`/`survey-pipeline`
  onto the spine as capacity allows (Phase 3+).
- Retire `sitehub` from the platform (standalone tool) or formalize it as an
  out-of-band integration.

---

## Related

- [gotchas-and-preservation-notes.md](gotchas-and-preservation-notes.md) · [architecture-mapping.md](architecture-mapping.md) · [compatibility-matrix.md](compatibility-matrix.md)
- Roadmap: [../09-Roadmap/refactor-roadmap.md](../09-Roadmap/refactor-roadmap.md) · [../09-Roadmap/phase1-kernel-substrate.md](../09-Roadmap/phase1-kernel-substrate.md)
