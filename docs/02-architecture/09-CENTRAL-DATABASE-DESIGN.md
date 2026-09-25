# Phase 1: 09 — Central Database Design

**Document Status:** Architecture Specification (Documentation Only — No Migrations)
**Phase:** Phase 1 — Target Architecture Specification
**Scope:** `02-licensing` target schema
**Reference:** `docs/01-audit/02-LICENSING-DATABASE-AUDIT.md` §4

> This is a conceptual data-model specification. It describes tables, columns, keys, and relationships to guide future migration design. **No `CREATE TABLE` / `ALTER TABLE` statements are to be executed from this document; no migration files are created in this phase.**

---

## 1. Design Principles Carried Over From the Existing Schema

- `ENGINE=InnoDB`, `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` — `EXISTING` convention, unchanged.
- Raw secrets (license keys) are never stored — only their SHA-256 hash. `EXISTING`, `INV-05`, unchanged.
- `CREATE TABLE IF NOT EXISTS` idiom for self-healing schema application (`LicensingAPI::ensureSchema()` already replays `install.sql` + `migrations/*.sql` on every deploy) — `EXISTING`, preserved; new tables follow the same idiom.
- `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` (and `ON UPDATE CURRENT_TIMESTAMP` where mutable) for all timestamp columns — `EXISTING` convention, unchanged.
- No `AUTO_INCREMENT` type change — `INT UNSIGNED` primary keys throughout, matching every existing table in this plugin (`DB-07` notes ID-type inconsistency exists *elsewhere* in the codebase, but within the licensing plugin itself all IDs are already consistently `INT UNSIGNED`).

---

## 2. `licensing_products` — `EXISTING`, unchanged

No structural change. Retained exactly as audited (`id`, `slug` unique, `name`, `created_at`).

## 3. `licensing_clients` — `EXISTING`, unchanged

No structural change. Retained exactly as audited (`id`, `name`, `email` indexed, `notes`, `created_at`).

## 4. `licensing_plans` — `TARGET`, slimmed

| Column | Type | Notes |
| :--- | :--- | :--- |
| `id` | `INT UNSIGNED AUTO_INCREMENT PK` | `EXISTING` |
| `product_id` | `INT UNSIGNED NOT NULL` | `EXISTING` |
| `slug` | `VARCHAR(64) NOT NULL` | `EXISTING` |
| `name` | `VARCHAR(160) NOT NULL` | `EXISTING` |
| `description` | `TEXT NULL` | `RECOMMENDED` addition — presentational only |
| `is_active` | `TINYINT(1) NOT NULL DEFAULT 1` | `RECOMMENDED` addition — lets an administrator retire a plan from future use without deleting historical reference from already-issued licenses |
| `created_at` | `DATETIME` | `EXISTING` |

**Removed:** `entitlements_json`. Its responsibility moves to `licensing_plan_modules` (§5) as a *template default*, and to `licensing_license_modules` (§8) as the *actual* per-license grant. This is the direct schema fix for `DB-03`.

Keys unchanged: `UNIQUE KEY uniq_plan_product_slug (product_id, slug)`, `KEY idx_plan_product (product_id)`.

## 5. `licensing_plan_modules` — `MISSING` today, `TARGET` new

Template default module set, consulted only at license-creation time (`04-ENTITLEMENT-ARCHITECTURE.md` §4).

| Column | Type | Notes |
| :--- | :--- | :--- |
| `id` | `INT UNSIGNED AUTO_INCREMENT PK` | |
| `plan_id` | `INT UNSIGNED NOT NULL` | |
| `module_key` | `VARCHAR(64) NOT NULL` | Plain string, not `ENUM` — see §11 for why |
| `created_at` | `DATETIME` | |

Keys: `UNIQUE KEY uniq_plan_module (plan_id, module_key)`.

## 6. `licensing_licenses` — `TARGET`, new (split from `licensing_installs`)

| Column | Type | Notes |
| :--- | :--- | :--- |
| `id` | `INT UNSIGNED AUTO_INCREMENT PK` | |
| `client_id` | `INT UNSIGNED NOT NULL` | `EXISTING` field, moved from `licensing_installs` |
| `product_id` | `INT UNSIGNED NOT NULL` | `EXISTING` field, moved |
| `plan_id` | `INT UNSIGNED NULL` | `EXISTING` field, moved — nullable, historical/informational only after issuance (`04` §4) |
| `label` | `VARCHAR(190) NOT NULL DEFAULT ''` | `EXISTING` field, moved |
| `license_key_hash` | `CHAR(64) NOT NULL` | `EXISTING` field, moved, `INV-05` |
| `status` | `ENUM('unactivated','trial','active','expired','suspended','revoked','cancelled') NOT NULL DEFAULT 'unactivated'` | `TARGET` — adds `unactivated` as a distinct value not present today (`03-LICENSE-LIFECYCLE.md` §1); `trial`/`cancelled` retained per that document's `ASSUMPTION`s, flagged `REQUIRES VERIFICATION` there |
| `issued_at` | `DATETIME` | `EXISTING`, moved |
| `starts_at` | `DATETIME` | `EXISTING`, moved |
| `expires_at` | `DATETIME NULL` | `EXISTING`, moved |
| `warning_days` | `INT UNSIGNED NOT NULL DEFAULT 7` | `MISSING` today, `TARGET` new — per-license override capability for the `08` §2 warning window; defaults to the locked value |
| `grace_days` | `INT UNSIGNED NOT NULL DEFAULT 7` | Same, for the commercial grace window |
| `activation_limit` | `INT UNSIGNED NOT NULL DEFAULT 1` | `EXISTING` field/value, moved — default changed conceptually to reflect the locked "1 License → 1 Installation" rule (today's schema default is already `1`, so no value change, only a documentation of *why* it is `1`) |
| `revoke_reason` | `VARCHAR(255) NULL` | `EXISTING`, moved |
| `metadata_json` | `LONGTEXT NULL` | `EXISTING` (`metadata_json` on `licensing_installs`), moved |
| `created_at` / `updated_at` | `DATETIME` | `EXISTING`, moved |

Keys: `UNIQUE KEY uniq_license_key_hash (license_key_hash)`, `KEY idx_license_client (client_id)`, `KEY idx_license_status (status)`, `KEY idx_license_product (product_id)`.

**Removed relative to `licensing_installs`:** `domain`, `domain_normalized`, `activation_count`, `installed_version`, `last_checkin_at`, `last_checkin_ip` — these are Installation-level facts, not License-level facts, and move to §7.

## 7. `licensing_installations` — `TARGET`, new (split from `licensing_installs` + `licensing_installation_bindings`)

| Column | Type | Notes |
| :--- | :--- | :--- |
| `id` | `INT UNSIGNED AUTO_INCREMENT PK` | |
| `license_id` | `INT UNSIGNED NOT NULL` | The binding target — see keys below for how 1:1-*active* is now enforced |
| `installation_id` | `CHAR(32) NOT NULL` | `EXISTING` value shape (`licensing_installation_bindings.installation_id`), moved |
| `domain` | `VARCHAR(190) NOT NULL` | `EXISTING`, moved from `licensing_installs.domain` |
| `domain_normalized` | `VARCHAR(190) NULL` | `EXISTING`, moved — see `05-INSTALLATION-ACTIVATION.md` §4 for the hard-vs-soft enforcement decision this column feeds |
| `installed_version` | `VARCHAR(40) NULL` | `EXISTING`, moved |
| `status` | `ENUM('active','suspended','revoked','superseded') NOT NULL DEFAULT 'active'` | `TARGET` — adds `superseded` (`MISSING` today) alongside the `EXISTING` `licensing_installation_bindings.status` values, moved. Binding-level status, independent of the License's own commercial `status` (`02-CENTRAL-LICENSING-DOMAIN.md` §1.5). `superseded` is set by an administrator-initiated reset (§ note below) and is terminal for that row — it never transitions back to `active`; a reset always produces a *new* row. |
| `deleted_at` | `DATETIME NULL` | `MISSING` today, `TARGET` new, `LOCKED` (resolves Finding F-03 / R13, `16-PHASE-1-ANTIGRAVITY-REVIEW.md`). Set together with `status = 'superseded'` at reset time; `NULL` for every row that has never been superseded. This is a soft-deactivation marker, not a soft-delete-and-hide flag — a `superseded` row remains fully queryable, it is simply no longer the license's current binding. |
| `active_license_id` | `INT UNSIGNED GENERATED ALWAYS AS (IF(status = 'active', license_id, NULL)) STORED` | `MISSING` today, `TARGET` new — a generated column that is `NULL` for every non-`active` row (`suspended`, `revoked`, `superseded`) and equal to `license_id` only for the row currently representing that License's live binding. Exists solely to carry the uniqueness constraint below without requiring MySQL's partial/filtered-index syntax (which InnoDB does not support); see note below. |
| `first_activated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | `EXISTING` (`licensing_installation_bindings.first_registered_at`), renamed for clarity |
| `last_seen_at` | `DATETIME` | `EXISTING`, moved |
| `last_seen_ip` | `VARCHAR(45) NULL` | `EXISTING`, moved |
| `created_at` / `updated_at` | `DATETIME` | `EXISTING`, moved |

Keys: `UNIQUE KEY uniq_installation_identity (installation_id)` (an Installation ID is globally unique across all licenses, matching today's `uniq_binding_identity` — this holds regardless of `status`, since an Installation ID must never be reused or reassigned, even to a superseded row of a different license), `UNIQUE KEY uniq_installation_active_license (active_license_id)` (**replaces** the earlier `UNIQUE (license_id)` design — see note below), `KEY idx_installation_license (license_id)` (supports "all Installations, including superseded history, for this License" lookups — no longer implied for free by a unique key on `license_id` alone), `KEY idx_installation_status (status)`. Foreign key: `FOREIGN KEY (license_id) REFERENCES licensing_licenses(id) ON DELETE RESTRICT` — see §12.

**Note on soft-deactivation and re-activation — `LOCKED`, resolves Finding F-03 and R13 (`REQUIRES VERIFICATION` in the original draft, now closed by explicit project-owner decision):** a License row must never be hard-deleted, and per this correction neither must a superseded `licensing_installations` row — `REQUIREMENTS.md` §8 requires the Central Server to retain "Installation records, Activation history, Audit logging," and a `DELETE FROM licensing_installations WHERE license_id = ?` on reset (the behavior an earlier draft of this document proposed, mirroring `LicensingAPI::resetBindings()`'s `EXISTING` `DELETE FROM licensing_installation_bindings WHERE install_id = ?`) permanently destroys exactly that history: initial activation timestamp, bound domain, installed version, last check-in IP.

Target reset procedure, replacing the hard delete:

```sql
UPDATE licensing_installations
   SET status = 'superseded', deleted_at = NOW()
 WHERE license_id = ? AND status = 'active';
-- then, in the same transaction:
INSERT INTO licensing_installations (license_id, installation_id, domain, ...)
     VALUES (?, ?, ?, ...);
```

Because `active_license_id` is a generated column that evaluates to `NULL` the instant `status` moves off `'active'`, the `UPDATE` above frees the `uniq_installation_active_license` slot for that `license_id` as a side effect of the status change itself — MySQL's `UNIQUE` index permits any number of `NULL` values, which is exactly the "partial unique index" behavior needed (InnoDB has no native `WHERE`-qualified/partial unique index, so this generated-column pattern is the standard workaround). The subsequent `INSERT` for the new Installation then succeeds, and both rows — old (`superseded`) and new (`active`) — remain permanently queryable under `idx_installation_license`, giving exactly the "this license was previously bound to installation A, is now bound to installation B" history `02-CENTRAL-LICENSING-DOMAIN.md` §3 point 3 already anticipated as a future nice-to-have; this correction makes it the Phase 1 baseline rather than deferred.

## 8. `licensing_license_modules` — `MISSING` today, `TARGET` new

The actual per-license entitlement grants (`04-ENTITLEMENT-ARCHITECTURE.md` §3).

| Column | Type | Notes |
| :--- | :--- | :--- |
| `id` | `INT UNSIGNED AUTO_INCREMENT PK` | |
| `license_id` | `INT UNSIGNED NOT NULL` | |
| `module_key` | `VARCHAR(64) NOT NULL` | `forms` \| `membership` \| `booking` in V1; extensible, see §11 |
| `created_at` | `DATETIME` | |

Keys: `UNIQUE KEY uniq_license_module (license_id, module_key)`, `KEY idx_license_modules_key (module_key)` (supports "which licenses have `booking` enabled" reporting). Foreign key: `FOREIGN KEY (license_id) REFERENCES licensing_licenses(id) ON DELETE CASCADE` — see §12.

Core modules are **not** rows here (`INV-06`, `04` §2) — absence of a row for a Core key is meaningless because Core keys never get rows in the first place.

## 9. `licensing_license_events` — `MISSING` today, `TARGET` new

Lifecycle audit trail (`03-LICENSE-LIFECYCLE.md` §6, `INV-08`).

| Column | Type | Notes |
| :--- | :--- | :--- |
| `id` | `INT UNSIGNED AUTO_INCREMENT PK` | |
| `license_id` | `INT UNSIGNED NOT NULL` | |
| `event_type` | `VARCHAR(32) NOT NULL` | `activate` \| `suspend` \| `revoke` \| `renew` \| `extend` \| `refresh` \| `create` |
| `actor_type` | `ENUM('admin','system') NOT NULL` | Distinguishes a Central administrator's manual action from the system's own implicit actions (e.g. the first-check-in Activate) |
| `actor_id` | `INT UNSIGNED NULL` | References the Central Server's own `users.id` when `actor_type = 'admin'`; `NULL` when `'system'` |
| `reason` | `VARCHAR(255) NULL` | `RECOMMENDED` required (application-level, not schema-level) for `suspend`/`revoke` |
| `metadata_json` | `LONGTEXT NULL` | Free-form — e.g. previous/new `expires_at` for a `renew`/`extend` event |
| `created_at` | `DATETIME` | |

Keys: `KEY idx_license_events_license (license_id, created_at)`. Foreign key: `FOREIGN KEY (license_id) REFERENCES licensing_licenses(id) ON DELETE CASCADE` — see §12.

This table is distinct from `licensing_checkins` (§10) by design — checkins are high-volume, unattended, external-facing phone-home traffic; events are low-volume, attributable, commercially significant state changes. `02-licensing/plugins/licensing/install.sql`'s own header comment already articulates exactly this reasoning for why checkins are kept separate from "any general audit mechanism" — this document extends the same reasoning to justify a *second*, equally separate table for lifecycle events rather than overloading `licensing_checkins` with non-checkin rows.

## 10. `licensing_checkins` — `EXISTING`, unchanged (minor addition recommended)

Retained as-is (`id`, `install_id`, `checked_at`, `ip`, `reported_domain`, `reported_version`, `response_status`, `failure_code`, `created_at`). `RECOMMENDED`: rename `install_id`'s conceptual referent from "an id in the old `licensing_installs` table" to "an id in the new `licensing_installations` table" (§7) — same column, same type, same values conceptually (it already stores the installation-binding's ID, not the license's ID, per the existing `Database::insert('licensing_checkins', ['install_id' => $install['id'], ...])` call sites, which pass the *install* row's ID). No structural change required.

## 11. Column Type Choice: `module_key` as `VARCHAR`, Not `ENUM`

Deliberate, and directly supports `04-ENTITLEMENT-ARCHITECTURE.md` §5's future-extensibility requirement: a `VARCHAR` column accepts a new module key (`editor`, `content`, or anything else) with **zero** schema migration — only an application-level allow-list change. An `ENUM` column would require an `ALTER TABLE` for every future module, which is exactly the kind of "redesign the licensing architecture to add a module" `REQUIREMENTS.md` §14 prohibits.

## 12. Referential Integrity — `RESOLVED`, Foreign Keys Adopted

`DB-06` flagged missing foreign keys on the current schema as a risk (orphaned rows on client/plan deletion). The existing plugin convention deliberately omits foreign keys (`install.sql`'s own header: "No FOREIGN KEY constraints, matching this codebase's existing plugin convention... referential integrity is enforced in application code"). The new schema introduces more inter-table relationships (License → Installation, License → Modules, License → Events) that are more central to correctness than the original three-table design, which made this a **higher-stakes** decision than the plugin convention was designed around.

**`LOCKED` — project-owner decision (this document's original draft left this as `R1`/`REQUIRES VERIFICATION`; it is now resolved):** new Central licensing tables introduced by this rebuild **MUST** use database-level foreign keys where the architecture defines a relationship, breaking from the existing no-FK plugin convention specifically for this subsystem. This does not retroactively add FKs to `licensing_products`/`licensing_clients`/`licensing_plans` or to any other, unrelated plugin's tables — it applies only to the new License-centric relationships this document introduces:

| Child table | Column | Constraint | Rationale |
| :--- | :--- | :--- | :--- |
| `licensing_installations` | `license_id` | `REFERENCES licensing_licenses(id) ON DELETE RESTRICT` | A License is never hard-deleted (only revoked, per `03-LICENSE-LIFECYCLE.md`) — `RESTRICT` makes that invariant enforceable at the database level, not just by application-code discipline, and matches this document's own §7 note that "a License should probably never be hard-deleted at all, only revoked" |
| `licensing_license_modules` | `license_id` | `REFERENCES licensing_licenses(id) ON DELETE CASCADE` | Entitlement grants have no independent meaning once their License is gone; cascading avoids an orphan-row cleanup step that application code would otherwise have to remember, which is exactly the category of gap (`DB-06`) this decision exists to close |
| `licensing_license_events` | `license_id` | `REFERENCES licensing_licenses(id) ON DELETE CASCADE` | Same reasoning as modules — an event row about a License that no longer exists (which, per the `RESTRICT` above, should in practice never actually happen) has no independent audit value |

Trade-off acknowledged and accepted: this breaks the plugin's established no-FK convention, and requires the Central Server's migration runner and any future data-repair tooling to respect `ON DELETE` ordering (e.g., a script that needs to remove a License entirely must first understand it cannot, by design, while active Installations reference it via `RESTRICT`). This is treated as a feature, not friction — it is precisely the referential-integrity gap `DB-06` identified, and "rely entirely on application code discipline" is the category of gap this whole rebuild exists to close, per `16-PHASE-1-ANTIGRAVITY-REVIEW.md` §5 item 1's rationale for confirming this as blocking.

## 13. Migration Numbering Note

`ASSUMPTION`, informational only (no migration is created in this phase): the Central Server's `02-licensing/plugins/licensing/migrations/` directory currently contains only `0024_installation_binding.sql` (`EXISTING`). A future implementation phase adding these tables should use a migration number consistent with that directory's own sequence (e.g. `0025_...`), not the client's separate `01-client/db/migrations/` numbering (which is already at `0024` for an unrelated reason — the two directories are independent sequences belonging to two different applications, verified directly by inspecting both).
