# Inventory CSV validation

> **Current status, 2026-10-17.** The text below this block is **historical**: it was written for `audit/settings-secrets-hardening` alone. The current state has been tested as one combined tree, on the local test-only branch `integration/final-check`, which merges three branches onto `origin/main` `ba4c9a5`:
>
> | Branch | Head | What it holds |
> |---|---|---|
> | PR #102 `fix/secret-handling-gaps` | `6fed518` | SMTP OAuth secrets, Multilang translate key, AI base URL (SSRF) |
> | `fix/licensing-module-guard` | `188808b` | Central `ModuleGuard`, per-tenant plan enforcement, plan CLIs |
> | `audit/settings-hardening-next` | this branch | The audit work, encrypt-first saves, `APP_SECRET` rotation tool, test hardening |
>
> Test results and the risk register are in `01-client/audit/integration-review/` (start with `README.md` and `10-final-release-readiness.md`).
>
> Integrated re-validation: 100 rows, 12 columns, LF line endings. `csv-verify.py` finds 99 VERIFIED and 1 NOT_IMPLEMENTED. The claim check (`integration-review/inventory-claims-verify.py`) confirms all 12 encryption claims and all 69 "Identical" parity claims. Eight rows were corrected after this document was written: the 4 secret rows fixed by #102 (`smtp_oauth_client_secret`, `smtp_oauth_refresh_token`, `ai_base_url`, `autotranslate_api_key`) and 4 rows with a wrong parity or value (`stripe_payment_field` parity n/a, `LICENSE_SERVER_URL` client-only, `OFFLINE_TOLERANCE_SECONDS` client-only and 7 days, `ALLOWED_MIMES` is `MediaLibrary::ALLOWED_MIMES` = jpeg, png, webp, gif). Parity totals are now Identical 69, Client-Only 17, Both Trees 13, n/a 1.

**File:** `01-client/audit/kohevo-settings-widget-inventory.csv`
**Checked:** 2026-10-17, branch `audit/settings-secrets-hardening` (on `origin/main` `ba4c9a5`)
**Tools:** Python 3 standard-library `csv`; `csv-verify.py` (strict source check); `inventory-corrections.py` (the corrections applied, each with its reason)

## Result

| Check | Result | Status |
|---|---|---|
| Header | Exactly 12 columns: `Domain, Component, Setting_Or_Widget, Type, Storage_Key, Input_Validation, Bounds_Enforced, Secret_Handling, Owner_Tab, Parity_Status, Disposition, Notes` | PASS |
| Data rows | **100** (was 137) | PASS |
| Row width | Every row has 12 fields | PASS |
| Duplicates | 0 duplicate `(Domain, Component, Setting_Or_Widget)` keys | PASS |
| Mandatory fields | No empty `Domain`, `Component`, `Setting_Or_Widget`, `Type`, `Parity_Status` or `Disposition` | PASS |
| Encoding | UTF-8, no BOM, LF line endings | PASS |
| **Key traced to source (strict)** | **99 VERIFIED, 1 NOT_IMPLEMENTED, 0 NOT_FOUND** | **PASS** |
| `Secret_Handling` on secret-like rows | Checked row by row against code. Two rows corrected to "encrypted, with plaintext fallback". | PASS |
| `Input_Validation` / `Bounds_Enforced` / `Notes` on rows not otherwise touched | Spot-checked only. One false claim was found and corrected (`ai_base_url` "strict SSRF validation"). | NOT VERIFIED |
| Exhaustiveness | Not claimed (see the last section) | NOT VERIFIED |

## What changed since the first audit

The first audit's CSV had 137 rows, and 66 of them named keys that do not exist in the source. A previous pass flagged them, and this pass reworked every one:

| Action | Rows | Where recorded |
|---|---|---|
| **Corrected** to the real key, block type or field type. The old name stays in the row's Notes as `[corrected 2026-10-17: was …]`. | 52 | The inventory itself; the full table is in `kohevo-option-disposition.md` |
| **Removed**, because the setting does not exist | 38 | `01-client/audit/kohevo-inventory-removed-rows.csv` (original line, name, reason) |
| **Kept, marked "Not implemented"**: `forms` `stripe_payment_field`, a documented deferral | 1 | Inventory |
| **Added**: `multilang-translate` `autotranslate_api_key` (real key, stored in plaintext) | 1 | Inventory |
| **Claim corrected**: `mcp-gateway` `ai_base_url` validation, and the plaintext fallback on two SMTP OAuth rows | 3 | Inventory Notes |

137 − 38 + 1 = **100** rows.

The earlier verifier counted any quoted occurrence of a word as a match. That let `header`, `paragraph` and `columns` pass as Studio blocks when they are not block types at all. `csv-verify.py` is now strict, with one rule per domain:

| Domain | Match rule |
|---|---|
| `Widget: Studio` | Must be a block id returned by a renderer's `type()`, or defined as `'type' => '<id>'` in a plugin |
| `Field: Forms` | Must be in `FormsAPI::SUPPORTED_TYPES` |
| Settings | An exact `'<plugin>.<key>'`, `'<key>'` or `name="<key>"` literal |
| Constants, config and tables | A whole-identifier match |

Rerun it with:

```bash
python3 -I 01-client/audit/review-package/csv-verify.py 01-client 01-client/audit/review-package/csv-row-verification.csv
```

## Distribution after correction

| Domain | Rows | VERIFIED | NOT_IMPLEMENTED |
|---|---|---|---|
| `Plugin` | 46 | 45 | 1 |
| `Core: System` | 27 | 27 | 0 |
| `Widget: Studio` | 16 | 16 | 0 |
| `Field: Forms` | 11 | 11 | 0 |
| **Total** | **100** | **99** | **1** |

**`Secret_Handling` values:**

| Value | Rows |
|---|---|
| plaintext | 60 |
| n/a | 28 |
| encrypted (AES-256-GCM) | 9 |
| encrypted, with plaintext fallback | 2 |
| signature verified | 1 |

The `forms` `sp_captcha_secret` row is "encrypted" and is now true (Forms fix in this pass). The plaintext secret-like rows are flagged as open risks: `autotranslate_api_key`, and the two SMTP OAuth rows with a fallback.

## Implemented, planned, retired, unverified

| Category | Rows | Where |
|---|---|---|
| Implemented | 99 | Inventory |
| Planned / deferred | 1 | Inventory, `Disposition = Not implemented` |
| Retired / never existed | 38 | `kohevo-inventory-removed-rows.csv` |
| Unverified | Descriptive columns of untouched rows | See the table above |

## What a static inventory cannot enumerate

- **Tenant-defined data.** Studio custom fields (29 meta field types, plus field sets per content type), form schemas and membership plans are tenant data.
- **Runtime-registered blocks.** Widget SDK blocks depend on which plugins are active. The inventory lists one representative row per bundled plugin; `kohevo-widget-test-matrix.md` lists every block id.
- **Hook-contributed settings.** Plugins can add booking settings cards through `Hook::doAction('booking_settings_save', $_POST)`.
- **Coverage gaps.** The inventory is not exhaustive. For example, about 224 distinct setting keys appear in `Database::setting()` calls in `01-client`, and many are not listed: landing-page, business-profile, Studio SEO/AI and Multilang switcher keys. Rows are only claims that have been checked, not a complete catalogue.
