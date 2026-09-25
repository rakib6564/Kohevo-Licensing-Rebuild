# 15 — Contributing

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

How to contribute code and documentation to Slate — and how to keep this
Documentation Hub **alive** so it stays the source of truth rather than rotting
into fiction. Embodies two vision principles ([00-Vision](../00-Vision/)):
*developer joy is a feature* and *process protects architecture*.

---

## 1. The prime directive: amend-first

**No large architectural change merges without its documentation change in the
same commit.** The docs lead the code. If your change contradicts a hub document,
you either (a) change your approach, or (b) change the document *deliberately and
in review*. Architecture-by-accident is the thing this hub exists to prevent.

A change needs a new **[ADR](../14-ADR/)** when it makes or reverses a
*structural* decision (a new cross-cutting pattern, a boundary move, a technology
choice, superseding an existing ADR). ADRs are append-only; a superseded one is
marked `Superseded by ADR-NNNN`, never deleted.

---

## 2. Development workflow

1. **Branch** off the default branch (never commit directly to it).
2. **Small, focused commits**; imperative messages (`Add PaymentGateway
   contract`, not `changes`).
3. **Open a PR**; fill the template (what, why, which hub docs touched, which
   invariants are relevant).
4. **Review applies to docs too** — doc PRs get the same scrutiny as code.
5. **Green CI required** — including architecture-conformance checks
   ([12-Testing](../12-Testing/architecture-conformance.md)).

---

## 3. Contributing a module

Follow [06-SDK/building-a-module.md](../06-SDK/building-a-module.md) and the
[module-development standards](../03-Standards/module-development-standards.md).
The **definition of done** (all must be true before merge):

- [ ] Owns only its own tables (slug-matched prefix); touches no other module's
      tables or classes.
- [ ] Communicates only via capability contracts, events, and extension points
      (the three channels — [§5](../01-Architecture/README.md)).
- [ ] Declares capabilities, permissions, settings, routes, nav in the manifest
      (data, not `boot()` code).
- [ ] Uses `Money` for all amounts; tenant-scopes all data (no raw
      `AND tenant_id`).
- [ ] Ships versioned migrations (no `ensureSchema` self-heal).
- [ ] Has unit + integration tests for money, auth, and tenancy paths.
- [ ] Any new structural decision has an ADR.

---

## 4. Reviewer checklist (the invariants, made actionable)

A reviewer confirms the change violates no
[§7 invariant](../01-Architecture/README.md):

- [ ] No module references another module's class/table.
- [ ] Every persistence call is tenant-scoped (or an audited `crossTenant`).
- [ ] Money is a `Money` value object end to end.
- [ ] A person is one `contacts` row (a profile, never a copy).
- [ ] Services resolved from the container — no `new` across a boundary.
- [ ] Public rendering goes through the one pipeline.
- [ ] Payments flow only through `PaymentGateway`.
- [ ] SDK-surface changes respect the [BC policy](../03-Standards/versioning-and-compatibility.md).

---

## 5. Documentation style

- Every doc starts with `# NN — Title` then `**Status:** … · **Applies to:** …`.
- Use the [canonical glossary](../README.md#canonical-glossary-fixed-vocabulary)
  exactly; never redefine a term locally.
- Keep the hub **high-level and stable**; volatile detail (exact signatures,
  schemas) graduates to per-layer `docs/design/<layer>.md` referenced from here.
- Cross-link with relative paths; prefer tables, diagrams, and contract sketches.
- State **why**, not just what — the reasoning is the part that ages well.

---

## 6. Your first contribution (walkthrough)

1. Read [00-Vision](../00-Vision/) and [01-Architecture](../01-Architecture/).
2. Pick a `good-first-issue`; branch.
3. Make the change; add/adjust tests; run CI locally ([12-Testing](../12-Testing/)).
4. Update any hub doc your change touches (amend-first).
5. Open the PR; walk the reviewer checklist yourself before requesting review.

---

## 7. Keeping the hub alive

| Cadence | Action |
|---|---|
| Every PR | amend-first; update touched docs; add ADR if structural |
| Every release | bump each doc's `Applies to`; reconcile doc↔code divergences as bugs |
| Quarterly | review pass — promote `Draft`→`Accepted`, mark superseded ADRs, prune graduated detail |

Divergence between a document and the code is a **defect**, tracked and fixed —
not an accepted fact of life. That discipline is what makes this hub worth
trusting in year ten.

---

## Related

- [03-Standards](../03-Standards/) · [06-SDK](../06-SDK/) · [12-Testing](../12-Testing/) · [14-ADR](../14-ADR/)
