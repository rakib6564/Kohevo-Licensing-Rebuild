# 06 — Manifest v2 Schema

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

The `module.json` manifest is the module's **declarative wiring** — data the
kernel reads *without executing the module*, so nav, routes, permissions, and the
capability graph can be assembled (and cached) cheaply. This is a covered,
semver'd part of the SDK surface ([03-Standards](../03-Standards/versioning-and-compatibility.md)).

> **Why declarative.** Today ([AUDIT-BRIEFING](../AUDIT-BRIEFING.md)) modules
> register nav/routes/widgets/cron imperatively inside `boot()`, so the kernel
> must boot every plugin just to know what routes exist. Moving the static parts
> to data lets the kernel introspect the whole system without side effects — and
> lets tooling validate a module before it ever runs.

---

## 1. Complete annotated example

```jsonc
{
  // ── Identity ──────────────────────────────────────────────
  "slug": "membership",                 // unique; MUST match table prefix (membership_*)
  "name": "Membership",
  "version": "1.2.0",                   // SemVer (03-Standards)
  "description": "Fixed-term memberships on core Contacts.",
  "author": "Slate",
  "requires_core": ">=1.0.0 <2.0.0",    // enforced at activation

  // ── Capability graph (dependency resolution) ─────────────
  "provides": [ "membership@1" ],       // capabilities this module offers others
  "requires": [ "identity@1", "payments@1" ],   // hard deps + version ranges; refused if unmet
  "optional": [ "notifications@1", "booking@^1" ], // soft — degrade if absent

  // ── Authorization (the ONLY declarative registration today) ─
  "permissions": [
    { "key": "membership.view",   "label": "View memberships" },
    { "key": "membership.manage", "label": "Create / edit / cancel memberships" }
  ],

  // ── Settings schema → auto-generated settings UI ─────────
  "settings": [
    { "key": "membership.currency", "type": "currency", "default": "USD", "label": "Currency" },
    { "key": "membership.grace_days", "type": "int", "default": 3, "min": 0, "max": 30 }
  ],

  // ── Routing & navigation ─────────────────────────────────
  "routes": [
    { "prefix": "/members", "handler": "PortalController", "methods": ["GET","POST"] }
  ],
  "nav": [
    { "group": "COMMERCE", "label": "Members", "href": "/admin/members",
      "icon": "id-card", "perm": "membership.view", "order": 20 }
  ],

  // ── Event & extension wiring ─────────────────────────────
  "subscribes": [ "payment.succeeded", "contact.created" ],
  "blocks":     [ "membership-signup", "member-portal" ],

  // ── Assets (no build step) ───────────────────────────────
  "assets": { "css": ["assets/members.css"], "js": ["assets/members.js"] }
}
```

---

## 2. Field reference

| Field | Type | Purpose |
|---|---|---|
| `slug`,`name`,`version`,`description`,`author` | string | identity; `slug` also fixes the table prefix |
| `requires_core` | semver range | activation refused outside the range |
| `provides` | `capability@major[]` | capabilities offered to other modules |
| `requires` / `optional` | `capability@range[]` | hard / soft dependencies (resolver input) |
| `permissions` | `{key,label}[]` | registered on activate; grantable in the roles editor |
| `settings` | typed schema | drives an auto-generated settings UI + validation |
| `routes` | `{prefix,handler,methods}[]` | public routes (PublicRouter) |
| `nav` | `{group,label,href,icon,perm,order}[]` | admin nav items |
| `subscribes` | `event[]` | domain events the module listens to |
| `blocks` | `blockType[]` | blocks the module registers |
| `assets` | `{css[],js[]}` | asset handles registered with the Asset Manager |

---

## 3. Validation

The packager/CLI (`bin/make`, `bin/package`) validates the manifest **before**
install: slug/prefix match, semver well-formedness, capability syntax, referenced
handlers/blocks exist, permission/setting key conventions
([03-Standards](../03-Standards/)). A manifest that passes is guaranteed to
install — no runtime surprises.

The manifest schema itself is versioned; new optional fields are MINOR additions,
never breaking older modules ([03-Standards/versioning-and-compatibility.md](../03-Standards/versioning-and-compatibility.md)).

---

## Related

- [base-classes-and-contracts.md](base-classes-and-contracts.md) · [building-a-module.md](building-a-module.md) · [event-catalogue.md](event-catalogue.md)
- [01-Architecture/plugin-architecture.md](../01-Architecture/plugin-architecture.md) · [03-Standards](../03-Standards/)
