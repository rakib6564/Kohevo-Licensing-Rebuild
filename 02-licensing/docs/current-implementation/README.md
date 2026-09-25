# Slate — Current Implementation Reference (As-Built)

**Status:** Living reference · **Describes:** the code as it exists **today**

> **Read this framing first.** This directory documents Slate **as built** — the
> real, current implementation. It is deliberately **separate from the frozen
> [Slate Platform Architecture v1.0](../README.md)** (sections `00`–`15`), which
> describes the *target*. In many places the two **differ on purpose**: today's
> code uses global classes, `class_exists` coupling, `ensureSchema` self-heal,
> DECIMAL money, and per-plugin customer tables; the target replaces those. When
> the two conflict, **the hub is the destination; this is the starting point**,
> and [09-Roadmap](../09-Roadmap/) is the bridge.

This reference exists so a new developer (or AI) can understand and safely modify
the *actual* system, and so nothing tacit is lost when implementation begins.

## Sections

**Preservation & migration (authored):**

| Doc | Covers |
|---|---|
| [gotchas-and-preservation-notes.md](gotchas-and-preservation-notes.md) | ✅ Tacit knowledge — hidden behaviors & hazards |
| [load-bearing-behaviors.md](load-bearing-behaviors.md) | ✅ The must-not-change preservation contract |
| [architecture-mapping.md](architecture-mapping.md) | ✅ Current → future component, by phase |
| [compatibility-matrix.md](compatibility-matrix.md) | ✅ Bridges, mechanisms, planned retirement |

**As-built encyclopedia (built from code extraction). Scope decision: "essentials,
then freeze" — the ✅ set is authored; the remaining docs are filled on demand as
Phase 1 reveals need.**

| Doc | Status | Covers |
|---|---|---|
| [database-as-built.md](database-as-built.md) | ✅ | Full schema, ownership, relationships, tenant handling, migration risks |
| [runtime-catalogues.md](runtime-catalogues.md) | ✅ | Hook catalogue (43), routes, cron, permissions |
| [modules-as-built.md](modules-as-built.md) | ✅ | Per-plugin inventory for all 19 (tables/routes/perms/hooks/APIs/deps/problems/migration) |
| [plugin-system-as-built.md](plugin-system-as-built.md) | ✅ | Lifecycle, boot sequence, manifest, load order, dependency handling |
| [security-as-built.md](security-as-built.md) | ✅ | Every security control implemented + gaps |
| [technical-debt.md](technical-debt.md) | ✅ | Debt register, known issues, developer notes, refactor candidates |
| core-structure.md | on demand | Folder/dir structure + core class inventory |
| bootstrap-and-lifecycle.md | on demand | Entry points, `config.php` boot order, request → response |
| services-as-built.md | on demand | Each current service in depth |
| rendering-as-built.md | on demand | Rendering pipeline, Theme, Block system, layout, templates |
| admin-and-portal.md | on demand | Admin architecture + Customer portal |
| performance-as-built.md | on demand | Bottlenecks, caching, scalability limits (summary lives in technical-debt.md) |

The preservation & migration set and the essential encyclopedia are authored from
direct code extraction (grep/read) so they are accurate, not inferred.
