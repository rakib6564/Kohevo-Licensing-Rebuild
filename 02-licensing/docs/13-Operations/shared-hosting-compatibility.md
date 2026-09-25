# 13 — Shared-Hosting Compatibility Strategy

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

The strategy that lets Slate run on commodity shared hosting **and** scale to
enterprise infrastructure from one codebase. Realizes the *optionality principle*
([00-Vision](../00-Vision/)) and ADR-0012.

> **Why this is a first-class concern.** "Deploy anywhere" is a core promise
> ([00-Vision](../00-Vision/)). If the architecture assumed Redis, a worker
> daemon, or a build pipeline, it would break that promise. Instead every
> scale-sensitive concern is an interface with a humble default.

---

## 1. The rule: humble default, optional heavy driver

Every concern that *could* demand infrastructure is defined as an interface with:
- a **shared-hosting default driver** that needs only PHP + MySQL + cron, and
- an **optional heavier driver** for VPS/enterprise,

selected by configuration. Modules code against the interface and never know which
driver is active.

| Concern | Interface | Default (shared) | Optional (scale) |
|---|---|---|---|
| Cache | `Cache` | file / APCu | Redis / Memcached |
| Queue / jobs | `Queue` | DB table drained by `cron.php` | Redis + persistent worker |
| Tenancy storage | tenancy driver | shared DB + `tenant_id` | schema-/DB-per-tenant |
| Search | `SearchIndex` | MySQL FULLTEXT | external engine (Meilisearch/ES) |
| Mail | `NotificationChannel` | SMTP (PHPMailer) | provider API / pool |
| Sessions | session driver | files | Redis / DB |

Moving a tenant from shared to enterprise is a **driver + config change**, not a
module rewrite — the whole point of the abstraction.

## 2. What Slate must NOT require by default

- **No Node/webpack/build step** — assets compose at runtime
  ([05-Rendering/assets.md](../05-Rendering/assets.md)).
- **No long-running daemon** — background work drains via `cron.php`; a worker is
  an *upgrade*, not a prerequisite.
- **No shell exec / exotic extensions** — stick to widely available PHP
  extensions (`pdo_mysql`, `mbstring`, `openssl`, `zip`, `gd`, `curl`,
  `fileinfo`).
- **No root/sysadmin access** — installable by a cPanel user via file upload +
  the web installer.

## 3. Performance under the humble defaults

Shared hosting can't hide behind big infrastructure, so the platform is efficient
by design: a **boot cache** removes per-request module scanning, settings are
cached, and public pages are full-page cached
([performance-and-caching.md](performance-and-caching.md)). The default posture is
*fast*, not merely *functional*.

## 4. Graceful degradation

- If APCu is absent, `Cache` falls back to file; if FULLTEXT is unavailable,
  search degrades to a bounded `LIKE`. Features detect capabilities and degrade,
  never hard-fail on a missing optional facility.
- Optional module dependencies degrade the same way
  ([01-Architecture/plugin-architecture.md](../01-Architecture/plugin-architecture.md)).

## 5. When to move up

Signals to adopt heavier drivers: sustained CPU/DB pressure, queue backlog under
cron cadence, noisy-neighbor tenant isolation needs, search latency, or
compliance/data-residency ([09-Roadmap v4](../09-Roadmap/implementation-roadmap.md)).
Because the seams exist from day one, the move is incremental and low-risk.

---

## Related

- [README.md](README.md) · [deployment.md](deployment.md) · [performance-and-caching.md](performance-and-caching.md)
- [01-Architecture/multi-tenancy.md](../01-Architecture/multi-tenancy.md) · [08-Modules/search.md](../08-Modules/search.md) · [ADR-0012](../14-ADR/)
