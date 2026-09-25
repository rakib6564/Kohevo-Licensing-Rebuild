# 12 — Architecture Conformance

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

How the [§7 architectural invariants](../01-Architecture/README.md) become
**automated CI gates** rather than tribal knowledge. This is the anti-erosion
mechanism: the reason a clean architecture *stays* clean as dozens of modules and
years of changes accumulate.

> **Why this matters most.** Boundaries that are only enforced by code review
> decay — a reviewer misses one `class_exists`, one unscoped query, one float
> total, and the erosion compounds. Encoding the invariants as checks makes
> violation a red build, not a someday-refactor.

---

## 1. The invariants, as checks

| # | Invariant | Automated check |
|---|---|---|
| 1 | No module references another module's class/table | static scan: a `Slate\Module\X` file may not reference `Slate\Module\Y\…`; SQL/table-name scan stays within the module's prefix |
| 2 | Every persistence call is tenant-scoped | no repository query outside `query()`/`crossTenant()`; grep for raw `tenant_id` string-building = fail |
| 3 | Money is a `Money` value object | no `float`/`DECIMAL` on money columns (schema lint); money params/returns typed `Money` |
| 4 | A person is one `contacts` row | no module migration creates a table with `email`+`name`+`phone` person shape; person data keyed by `contact_id` |
| 5 | Services resolved from the container | no `new` of a service/repository across a module boundary; forbid `class_exists('*API')` |
| 6 | Public render goes through the one pipeline | no module emits a raw `<!doctype>`/`<html>` document |
| 7 | Payments only via `PaymentGateway` | no reference to a provider SDK (`\Stripe\`, `api.stripe.com`) outside the payments module |
| 8 | SDK surface respects BC policy | public-symbol diff vs the last release; a removed/renamed public symbol without a MAJOR bump = fail |

## 2. How the checks run

- **Static analysis pass** (namespace/reference/schema scans) + targeted grep
  rules, run in CI on every PR ([README.md](README.md)).
- **A public-API snapshot** — the exported SDK symbols are captured; a diff that
  removes or changes one without a version bump fails ([03-Standards/versioning-and-compatibility.md](../03-Standards/versioning-and-compatibility.md)).
- **Schema lint** — reads each module's migrations and asserts prefix ownership,
  `tenant_id` presence, and money-as-integer.

## 3. Example rules

```
# invariant 5/7 — no cross-module concrete coupling
grep -rE "class_exists\('(\w+)API'\)|\\\\Stripe\\\\|api\.stripe\.com" modules/ \
  --exclude-dir=modules/payments  →  MUST be empty

# invariant 2 — no hand-built tenant predicate
grep -rE "AND\s+tenant_id\s*=" modules/  →  MUST be empty (scoping is the base repo's job)

# invariant 3 — no DECIMAL money columns
schema-lint --forbid-decimal-money modules/*/migrations  →  MUST pass
```

(Illustrative — real checks are proper static analysis, not only grep, to avoid
false positives.)

## 4. Failure = blocked merge

A conformance failure blocks the merge exactly like a failing unit test. New rules
are added as new invariants are ratified (via an [ADR](../14-ADR/)), so the gate
grows with the architecture.

## 5. Relationship to review

Conformance catches the *mechanical* violations so human review can focus on the
*judgment* ones (is this the right boundary? is this event well-named?). Together
they keep the [reviewer checklist](../15-Contributing/) cheap and reliable.

---

## Related

- [README.md](README.md) · [01-Architecture §7](../01-Architecture/README.md) · [03-Standards](../03-Standards/) · [15-Contributing](../15-Contributing/) · [14-ADR](../14-ADR/)
