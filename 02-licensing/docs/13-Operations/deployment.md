# 13 — Deployment

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

How Slate is installed, configured, upgraded, and backed up — optimized for the
default posture (upload to shared cPanel under a sub-path) while allowing a
VPS/container posture without code changes.

---

## 1. Install (upload-and-run)

1. Upload the tree to the web root (or a sub-path like `/slate/`).
2. Visit `/install` — a two-step wizard: DB credentials + app URL, then the first
   admin account.
3. The installer writes environment config, runs the initial
   [migrations](../11-Database/migrations.md), and marks the install complete.

No `composer install` on the server, no build. The only vendored dependency
(PHPMailer) ships in the tree.

## 2. Front controller & routing

- A single **front controller** (`public/index.php`) handles all web + API
  traffic behind `.htaccess` rewrites; `/includes` and internals stay
  non-servable.
- **Base-path aware** — everything (routes, asset URLs, cookies) respects the
  `/slate/` sub-path so a subdirectory install behaves identically to a root one.

## 3. Configuration & secrets

- Config comes from the **environment** (`.env` in dev, host env vars in prod) —
  never hardcoded, never committed. Keys: app URL, app secret (AES-256-GCM/HMAC),
  DB credentials, cron secret.
- `.env` and internal dirs are denied by `.htaccess`/`Require all denied`; the
  `.env` must never ship in a distributable package
  ([10-Security](../10-Security/)).

## 4. Upgrades

- Upload new files; on first request the **migration runner** applies pending
  migrations in dependency order ([migrations.md](../11-Database/migrations.md)).
- **Backward-compatible, near-zero-downtime** changes (add→backfill→switch→drop
  across releases) so an upgrade doesn't require a maintenance window.
- Module upgrades run their own migrations on version bump
  ([01-Architecture/plugin-architecture.md](../01-Architecture/plugin-architecture.md)).

## 5. Cron

- A secret-gated `cron.php` (or a real system cron on VPS) runs scheduled
  jobs: reminders, webhook delivery/retries, sitemap rebuilds, queue draining
  ([performance-and-caching.md](performance-and-caching.md)).
- Under the queue driver, `cron.php` is the shared-hosting worker; a VPS uses a
  persistent worker instead — same job code.

## 6. Backups & recovery

- **Shared DB:** standard DB dump + the `uploads/` tree.
- **DB-per-tenant:** per-tenant dump/restore/move — the abstraction that makes
  tenant export and migration cheap ([01-Architecture/multi-tenancy.md](../01-Architecture/multi-tenancy.md)).
- Restores are tested; a backup you haven't restored is a hope, not a backup.

## 7. Environments

- **Dev** (display errors on, verbose logs, reversible migrations exercised) is
  distinct from **prod** (errors off, forward-only migrations). The distinction is
  config, not code ([10-Security/error-handling.md](../10-Security/error-handling.md)).

---

## Related

- [README.md](README.md) · [shared-hosting-compatibility.md](shared-hosting-compatibility.md) · [performance-and-caching.md](performance-and-caching.md)
- [11-Database/migrations.md](../11-Database/migrations.md) · [10-Security](../10-Security/)

---

## Kill-switches: how to actually flip one

Every render cutover in this codebase ships with a switch that forces the old
path. They are the lever you reach for when a deploy goes wrong, so the failure
mode that matters is **flipping one and having nothing happen**.

That is a live trap, found by hitting it: the switches do not all use the same
key format, and a plausible-looking `UPDATE` can be completely inert while
appearing to succeed.

### The switches

| setting key (as stored) | forces | read by |
| --- | --- | --- |
| `content-builder.document_engine` | legacy page frame | `ContentBuilderAPI::getSiteSetting` |
| `content-builder.render_engine` | legacy body renderer | `ContentBuilderAPI::getSiteSetting` |
| `content-builder.token_bridge` | disables the `--accent` alias layer | `ContentBuilderAPI::getSiteSetting` |
| `content-builder.inject_slate_tokens` | stops `--slate-*` head injection | `ContentBuilderAPI::getSiteSetting` |
| `maintenance_mode` | branded 503 for visitors | `Database::setting` |
| `small-business-kit.inline_font` | re-inlines the display font | `Database::setting` |

**Plugin switches are namespaced; core's are not.** Content-builder's use
`content-builder.<key>` and small-business-kit's use `small-business-kit.<key>`;
only genuinely core switches such as `maintenance_mode` are bare.
`ContentBuilderAPI::getSiteSetting('document_engine')` reads
`content-builder.document_engine`. Setting a row with the bare key
`document_engine` writes a value nothing ever reads — the `UPDATE` reports one
row changed and the site does not budge.

### Flipping one

Preferred, because it cannot get the key wrong:

```php
ContentBuilderAPI::setSiteSetting('document_engine', 'legacy');   // content-builder
Database::setSetting('maintenance_mode', '1');                    // core
```

By SQL, when PHP is not an option — note the prefix:

```sql
INSERT INTO settings (tenant_id, setting_key, setting_value)
VALUES (1, 'content-builder.document_engine', 'legacy')
ON DUPLICATE KEY UPDATE setting_value = 'legacy';
```

### Verifying it took

Do not assume. Both of these were confirmed against a live suite: the namespaced
key demotes the engine and three assertions fail; the bare key changes nothing.

```sql
SELECT setting_key, setting_value FROM settings
 WHERE setting_key LIKE '%document_engine%';
```

Two rows means someone has already written the bare key. The namespaced one is
the live value; the bare one is inert and should be deleted so the next person
does not read it and conclude the switch is on.

On the public site the document engine also announces itself, which is the
fastest check of all:

```bash
curl -sI https://<host>/<base>/p/<slug> | grep -i x-slate-document-engine
```
