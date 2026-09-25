# 03 — Versioning & Backward-Compatibility Policy

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

How Slate versions its core, its SDK, and its modules — and the promise it makes
about not breaking what's built on it. This policy is the precondition for a
third-party ecosystem (v5): developers only build on a surface whose stability is
guaranteed. Realizes ADR-0009.

---

## 1. Semantic Versioning everywhere

Core, the SDK, and every module use **SemVer** `MAJOR.MINOR.PATCH`:

- **MAJOR** — a breaking change to a *public* surface (§2).
- **MINOR** — backward-compatible additions (new capability, new event, new
  optional manifest field).
- **PATCH** — backward-compatible fixes.

Capability versions carry the same semantics: a module `requires` `payments@^1`;
a provider offering `payments@2` no longer satisfies it, and the
[dependency resolver](../01-Architecture/plugin-architecture.md#4-dependency-resolution)
flags the mismatch at activation.

---

## 2. What is "public" (covered) vs "internal" (free to change)

The **[SDK](../06-SDK/)** is the contract. Only these are covered by the BC
promise:

| Covered (public) | Not covered (internal) |
|---|---|
| SDK base classes + capability interfaces | Concrete service implementations |
| The manifest schema | Kernel internals, container wiring |
| The typed event + extension-point catalogue | Private methods, un-exported classes |
| Documented HTTP API (`/api/v1`) | DB schema of *other* modules |
| Permission-key + setting-key conventions | Rendering internals below the Block contract |

If it isn't exposed through the SDK, it may change in any release. This is what
lets the platform be rebuilt internally for a decade without breaking modules.

---

## 3. Deprecation lifecycle

Breaking a public surface is a multi-release process, never abrupt:

1. **Deprecate (MINOR).** The old surface keeps working; it emits a deprecation
   notice (logged in dev, surfaced in the SDK docs) and points to the
   replacement. A superseding [ADR](../14-ADR/) is recorded.
2. **Coexist.** Old and new run side by side for **at least one full MINOR
   cycle** (target: ≥6 months for the public API/SDK).
3. **Remove (MAJOR only).** The old surface is deleted only in a MAJOR release,
   with the removal listed in the upgrade guide.

Breaking changes are **gated to MAJOR versions**. A MINOR/PATCH that breaks a
covered surface is a release bug.

---

## 4. HTTP API versioning

- The API is versioned in the path (`/api/v1`). A new incompatible shape is a new
  version namespace (`/api/v2`), not a mutation of `v1`.
- `v1` remains supported through the deprecation window after `v2` ships.
- Error envelope and pagination shapes are part of the covered surface
  ([07-API/versioning-and-errors.md](../07-API/versioning-and-errors.md)).

---

## 5. Core ↔ module compatibility

- A module declares `requires_core` (e.g. `>=1.0.0 <2.0.0`). Core refuses to
  activate a module whose range it doesn't satisfy.
- Core MINOR releases never break modules built against an earlier MINOR of the
  same MAJOR. Module authors get the full deprecation window to adapt before a
  core MAJOR.

---

## 6. Documentation & data versioning

- **This hub is versioned with the platform.** Each document's `Applies to`
  header states its version range; a release bumps them and reconciles
  divergences ([15-Contributing](../15-Contributing/)).
- **Migrations are forward-only in production** but authored reversible; a schema
  change ships with the code MINOR that needs it and never assumes an
  out-of-order apply ([11-Database/migrations.md](../11-Database/migrations.md)).

---

## Related

- [README.md](README.md) · [module-development-standards.md](module-development-standards.md)
- [06-SDK](../06-SDK/) · [07-API/versioning-and-errors.md](../07-API/versioning-and-errors.md) · [ADR-0009](../14-ADR/)
