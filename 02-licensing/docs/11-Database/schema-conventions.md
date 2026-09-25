# 11 — Schema Conventions

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

The rules every table follows so the platform's guarantees (tenant isolation,
money integrity, introspection) hold uniformly. These conventions are enforced in
review and by [conformance checks](../12-Testing/architecture-conformance.md).

---

## 1. Engine & charset

- **InnoDB**, `utf8mb4` / `utf8mb4_unicode_ci` everywhere.
- Foreign keys where integrity matters (cascade deletes for owned children);
  indexes on every FK and every column used in a `WHERE`/`ORDER BY` hot path.

## 2. Naming

| Thing | Rule | Example |
|---|---|---|
| Table | `<module-prefix>_<name>`, **prefix matches slug** | `membership_plans` |
| Core table | bare domain name | `contacts`, `settings` |
| PK | `id` BIGINT UNSIGNED AUTO_INCREMENT | |
| Tenant scope | `tenant_id` on **every** domain table | |
| FK column | `<entity>_id` | `contact_id` |
| Timestamps | `created_at`, `updated_at` | |
| Money | `<name>` as BIGINT minor units + `<name>_currency` (or a shared currency) | `total`, `total_currency` |

Prefix-matches-slug (fixing today's `contentbuilder_`/`medialibrary_` drift) is
what lets tooling map a table to its owning module and enforce ownership.

## 3. Tenancy (invariant #2)

Every domain table has `tenant_id` (BIGINT UNSIGNED, default 1) and includes it
in its primary lookups' indexes (`(tenant_id, …)`). The base repository injects
the predicate; the column existing everywhere is what makes that possible.

## 4. Money (invariant #3, ADR-0011)

- Money is stored as **integer minor units** (`BIGINT`), never `DECIMAL`/`FLOAT`.
- Currency travels with the amount (per-column or a table-level currency).
- The `money()` column helper + `Money` value object are the only sanctioned
  representation; a total is never a float in code or schema. (Fixes shop's
  `DECIMAL`.)

## 5. Identity (invariant #4)

- People live in core `contacts`. A module **never** creates a person table; it
  creates a profile table keyed by `contact_id`
  ([02-Domain/identity-contacts.md](../02-Domain/identity-contacts.md)).
- `email`/`name`/`phone` are not duplicated into module tables — they're read
  from the Contact.

## 6. Ownership (invariant #1)

A module's migrations create/alter/drop **only** tables under its own prefix.
Shared tables across modules are prohibited (a shared concept becomes a capability
or an event, not a shared table). Today ownership holds by convention — each plugin
uses its own prefix — but nothing *enforces* it; declared ownership makes a prefix
collision impossible rather than merely unlikely.

## 7. Soft-delete & audit

- Soft-delete (`deleted_at`) only where recovery/compliance needs it; otherwise
  hard-delete with FK cascade. Be explicit per table.
- Sensitive mutations are recorded in the [audit log](../13-Operations/logging-and-auditing.md),
  not inferred from row history.

## 8. Core table catalogue (reference)

The core owns: `tenants`, `roles`, `role_permissions`, `users`, **`contacts`**
(+ `identities`, contact profiles/emails/phones/tags), `settings`, `modules`,
`audit_log`, `lang_overrides`, `media_files`, `media_usage`, plus `migrations`
and `login_attempts`. Modules own everything else under their prefixes.

---

## Related

- [README.md](README.md) · [repository-service-pattern.md](repository-service-pattern.md) · [migrations.md](migrations.md)
- [02-Domain/identity-contacts.md](../02-Domain/identity-contacts.md) · [07-API/payments.md](../07-API/payments.md) · [ADR-0011](../14-ADR/)
