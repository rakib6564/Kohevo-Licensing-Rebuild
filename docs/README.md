# Documentation

| Section | What's in it |
|---|---|
| [`00-project/`](00-project/) | Requirements, decisions, the master [plan](00-project/PLANNING.md) |
| [`01-audit/`](01-audit/) | Audit of the pre-rebuild licensing code (state, database, auth, installer, modules, security, gaps, risks) |
| [`02-architecture/`](02-architecture/) | Target architecture: licensing domain, lifecycle, entitlements, activation, Global License Guard, module guards, expiry / grace / offline, database design, **API contract**, security, migration and compatibility |
| [`03-implementation/`](03-implementation/) | Phase notes — security hardening (11), legacy handling (13), production QA (14) |
| [`04-qa/`](04-qa/) | Test reports (Phase 12) |
| [`05-guides/`](05-guides/) | How-to guides: [French / i18n](05-guides/I18N.md), [booking-widget embedding](05-guides/EMBEDDING.md), [notification emails](05-guides/EMAIL-TEMPLATES.md) |
| [`06-operations/`](06-operations/) | [Releasing](06-operations/RELEASING.md) and [shared-hosting deployment](06-operations/SHARED-HOSTING.md) |
| [`releases/`](releases/) | Per-release audit reports |

Also:

- [`../CHANGELOG.md`](../CHANGELOG.md) — what changed in each version
- [`../production-artifacts/`](../production-artifacts/) — server setup: Apache, Nginx, cron, env, security checklist, go-live checklist
- [`../02-licensing/docs/`](../02-licensing/docs/) — the platform shell's technical hub (plugin API, building plugins, design system, ADRs). It is the platform-level "constitution"; the
  client tree carries the same shell.
- `01-client/INSTALL.md`, `02-licensing/INSTALL.md` — per-app installation
- `01-client/SECURITY.md`, `02-licensing/SECURITY.md` — per-app configuration and secrets notes

The numbered folders 00–04 record the licensing rebuild as it happened (audit → architecture → implementation → QA);
05–06 are living guides and are kept current with each release.
