# 09 — Roadmap

**Status:** Draft · **Applies to:** Slate v1.x → v5.x

This section holds the two roadmaps that connect **today's code** to the
**blueprint** in the rest of this hub, and then carry that blueprint forward
across the platform's multi-version life. It is the realization of the vision's
governing principle — **reveal, don't rebuild** ([00-Vision](../00-Vision/)): the
path is incremental and backward-compatible, with **no big-bang rewrite** at any
point.

Everywhere else in the hub describes the *destination* — the ideal platform
adapted to Slate's real constraints. This section is the only place that
describes the *journey*, and the only place that names where today's code
diverges from the blueprint. When the design and the code disagree, the gap is
tracked here (never silently baked into the design).

---

## The two roadmaps, and how they relate

There are two different questions, so there are two documents. They share one
architecture but answer to different clocks.

| | [Refactor roadmap](refactor-roadmap.md) | [Implementation roadmap](implementation-roadmap.md) |
|---|---|---|
| **Question** | How do we get *this* code to the blueprint? | How does the platform *evolve* over its life? |
| **Unit** | Phases 0–5 (internal, mostly invisible) | Versions v1.x–v5.x (product-visible) |
| **Sequenced by** | "what gets exponentially harder later" | "what each version unlocks for the next" |
| **Audience** | maintainers doing the spine-work | decision-makers, module authors, adopters |
| **Success** | the code *is* the blueprint | the platform delivers the vision at each tier |
| **Reversibility** | contract-preserving; no behaviour change | additive; SDK changes only under BC policy |

**The relationship in one sentence:** the **refactor roadmap** is how the current
codebase earns the right to be called a platform (it builds the kernel substrate,
identity, contracts, and render stack the blueprint assumes); the
**implementation roadmap** is what we then *build on that platform*, version after
version, up to a public SaaS and marketplace.

They interlock rather than run in sequence:

- Refactor **Phase 0–3** is the load-bearing content of implementation **v1.x**
  (the stable kernel foundation). You cannot ship v1.x without doing that
  spine-work — they are the same work described from two angles.
- Refactor **Phase 4** (render/theme unification) is what implementation **v2.x**
  (the website platform + page builder) is built *on*. The refactor makes the
  page builder cheap; v2.x is the product that results.
- Refactor **Phase 5** (performance & scale) hardens the substrate that
  implementation **v3.x–v4.x** lean on as tenant and module counts grow.
- Everything beyond Phase 5 is pure forward evolution — no more "catching the code
  up," only building outward. That is where the implementation roadmap takes over
  entirely (**v4.x–v5.x**).

```
Refactor:        P0 ─ P1 ─ P2 ─ P3 ──── P4 ──────── P5 ─────────▶ (blueprint reached)
                  └──────┬──────┘        │            │
Implementation:      v1.x kernel    v2.x website   v3.x business  v4.x enterprise  v5.x SaaS
                   (foundation)    (+ page builder)  (verticals)    (tenancy/RBAC)  (marketplace)
```

---

## How to read this section

- **Deciding what to build next?** Read the [implementation roadmap](implementation-roadmap.md)
  for the version themes, then confirm the enabling refactor phase is done.
- **Doing the spine-work?** Read the [refactor roadmap](refactor-roadmap.md)
  top-to-bottom; the ordering is not negotiable — it is chosen so nothing gets
  harder by being deferred.
- **Reviewing a change?** Both roadmaps defer to the same authorities: the
  invariants and ADR index in [01-Architecture](../01-Architecture/), the
  canonical glossary and layer-boundary table in the [hub README](../README.md),
  and the decision records in [14-ADR](../14-ADR/). If a roadmap step and an ADR
  disagree, the ADR wins and the roadmap is amended.

---

## Governing rules (apply to both roadmaps)

These come straight from the vision and architecture; the roadmaps only *sequence*
them, never soften them.

1. **No big-bang rewrite.** Every step is a contract-preserving refactor or an
   additive feature. The hook system, `tenant_id` columns, centralized Stripe
   gateway, and the `content-builder` block registry are kept and *formalized*,
   not replaced. (Vision §2; [ARCHITECTURE-ROADMAP §10](../ARCHITECTURE-ROADMAP.md).)
2. **Boundaries before features.** We invest in contracts, events, and ownership
   rules *before* the verticals that consume them. No new module ships as an
   island: it targets a kernel service, or that service is built first.
3. **One concept, one owner.** The roadmaps actively *retire* duplication (five
   person tables, five token vocabularies, three admin component kits, two
   settings accessors), never add to it.
4. **Process is part of the architecture.** Version control, tests, and CI
   ([12-Testing](../12-Testing/)) are Phase 0 — the prerequisite for safely doing
   anything else, not a later polish.
5. **The blueprint leads.** If a roadmap step would violate an
   [architectural invariant](../01-Architecture/#7-architectural-invariants-must-always-hold),
   the step is wrong, not the invariant.

---

## Contents

- **[refactor-roadmap.md](refactor-roadmap.md)** — the incremental, backward-
  compatible path from today's code to the blueprint (Phases 0–5), plus an
  explicit *what NOT to do*.
- **[implementation-roadmap.md](implementation-roadmap.md)** — the v1→v5 product
  evolution, each version with its theme, deliverables, the architecture it
  establishes, and what it unlocks next.
